<?php

declare(strict_types=1);

namespace AdrielPartners\WpAudioBuddy\Controllers;

use AdrielPartners\WpAudioBuddy\Data\JobRepository;
use AdrielPartners\WpAudioBuddy\Data\GeneratedOutputRepository;
use AdrielPartners\WpAudioBuddy\Data\LoggerRepository;
use AdrielPartners\WpAudioBuddy\Data\Meta;
use AdrielPartners\WpAudioBuddy\Data\TranscriptRepository;
use AdrielPartners\WpAudioBuddy\Services\QueueService;

if (! defined('ABSPATH')) {
    exit;
}

final class MediaController
{
    public function __construct(
        private SettingsController $settings,
        private QueueService $queue,
        private LoggerRepository $logger,
        private JobRepository $jobs,
        private TranscriptRepository $transcripts,
        private GeneratedOutputRepository $outputs
    ) {
        add_filter('attachment_fields_to_edit', [$this, 'attachment_fields'], 10, 2);
        add_filter('attachment_fields_to_save', [$this, 'save_attachment_fields'], 10, 2);
        add_filter('media_row_actions', [$this, 'row_actions'], 10, 2);
        add_action('admin_post_wpab_transcribe', [$this, 'handle_transcribe']);
        add_action('admin_post_wpab_excerpt', [$this, 'handle_excerpt']);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
    }

    public function assets(): void
    {
        wp_enqueue_style('wpab-admin', WPAB_URL . 'admin/assets/admin.css', [], WPAB_VERSION);
    }

    public function attachment_fields(array $form_fields, \WP_Post $post): array
    {
        if (! Meta::is_audio_attachment($post->ID)) {
            return $form_fields;
        }

        $status = Meta::transcript_status($post->ID);
        $excerpt_status = Meta::excerpt_status($post->ID);
        $transcript_error = (string) get_post_meta($post->ID, Meta::TRANSCRIPT_ERROR, true);
        $excerpt_error = (string) get_post_meta($post->ID, Meta::EXCERPT_ERROR, true);
        $chunk_progress = Meta::chunk_progress_label($post->ID);
        $mode = (string) $this->settings->get('processing_mode', 'auto');
        $worker_configured = '' !== trim((string) $this->settings->get('worker_url', '')) && '' !== trim((string) $this->settings->get('worker_shared_secret', ''));
        $worker_enabled = $worker_configured && 'wordpress_only' !== $mode;

        $job = $this->jobs->get_latest_for_attachment($post->ID);
        $job_status = $job ? (string) ($job['status'] ?? '') : '';
        $job_operation = $job ? (string) ($job['operation'] ?? '') : '';
        if ('' !== $job_status && 'transcribe' === $job_operation) {
            $status = $this->map_job_status_for_display($job_status);
        }

        // Also try to find a separate excerpt job for excerpt status display.
        $excerpt_jobs = $this->jobs->get_by_attachment($post->ID, 5);
        foreach ($excerpt_jobs as $ej) {
            if (('excerpt' === ($ej['operation'] ?? '')) && '' !== ($ej['status'] ?? '')) {
                $excerpt_status = $this->map_job_status_for_display((string) $ej['status']);
                break;
            }
        }

        $transcribe_url = wp_nonce_url(admin_url('admin-post.php?action=wpab_transcribe&attachment_id=' . $post->ID), 'wpab_transcribe_' . $post->ID);
        $excerpt_url = wp_nonce_url(admin_url('admin-post.php?action=wpab_excerpt&attachment_id=' . $post->ID), 'wpab_excerpt_' . $post->ID);

        $actions = '<div class="wpab-media-actions">';
        $actions .= '<p><strong>' . esc_html__('Transcription status:', 'wp-audio-buddy') . '</strong> ' . esc_html($status) . '</p>';
        if ('' !== $chunk_progress) {
            $actions .= '<p class="wpab-chunk-progress"><strong>' . esc_html__('Progress:', 'wp-audio-buddy') . '</strong> ' . esc_html($chunk_progress) . '</p>';
        } elseif ($worker_enabled && in_array($status, ['queued', 'running'], true)) {
            $actions .= '<p class="wpab-worker-progress"><strong>' . esc_html__('Worker:', 'wp-audio-buddy') . '</strong> ' . esc_html__('Processing on VPS worker…', 'wp-audio-buddy') . '</p>';
        }
        $actions .= '<p><strong>' . esc_html__('Excerpt status:', 'wp-audio-buddy') . '</strong> ' . esc_html($excerpt_status) . '</p>';
        $actions .= '<p><a class="button button-primary wpab-action-btn" href="' . esc_url($transcribe_url) . '">' . esc_html__('Transcribe Audio', 'wp-audio-buddy') . '</a>';
        $actions .= '<a class="button wpab-action-btn" href="' . esc_url($excerpt_url) . '">' . esc_html__('Generate Excerpt', 'wp-audio-buddy') . '</a></p>';

        if ('' !== $transcript_error) {
            $actions .= '<div class="notice notice-error inline"><p><strong>' . esc_html__('Transcription error:', 'wp-audio-buddy') . '</strong> ' . esc_html($transcript_error) . '</p></div>';
        }

        if ('' !== $excerpt_error) {
            $actions .= '<div class="notice notice-error inline"><p><strong>' . esc_html__('Excerpt error:', 'wp-audio-buddy') . '</strong> ' . esc_html($excerpt_error) . '</p></div>';
        }

        $actions .= '</div>';

        $form_fields['wpab_actions'] = [
            'label' => __('WP Audio Buddy', 'wp-audio-buddy'),
            'input' => 'html',
            'html' => $actions,
            'show_in_edit' => true,
            'show_in_modal' => true,
        ];

        $form_fields[Meta::TRANSCRIPT] = [
            'label' => __('Transcription', 'wp-audio-buddy'),
            'input' => 'textarea',
            'value' => $this->latest_transcript_text($post->ID),
            'helps' => wpab_transcript_meta_html($post->ID, $job),
            'show_in_edit' => true,
            'show_in_modal' => true,
        ];

        $form_fields[Meta::EXCERPT] = [
            'label' => __('Excerpt', 'wp-audio-buddy'),
            'input' => 'textarea',
            'value' => $this->latest_excerpt_text($post->ID),
            'helps' => wpab_excerpt_meta_html($post->ID),
            'show_in_edit' => true,
            'show_in_modal' => true,
        ];

        $form_fields[Meta::TOPICS] = [
            'label' => __('Topic Tags', 'wp-audio-buddy'),
            'input' => 'textarea',
            'value' => get_post_meta($post->ID, Meta::TOPICS, true),
            'helps' => wpab_topics_meta_html($post->ID),
            'show_in_edit' => true,
            'show_in_modal' => true,
        ];

        // Inline copy-to-clipboard JS for the attachment edit/modal screens.
        $form_fields['wpab_copy_buttons'] = [
            'label' => __('Copy', 'wp-audio-buddy'),
            'input' => 'html',
            'html' => wpab_copy_buttons_html($post->ID),
            'show_in_edit' => true,
            'show_in_modal' => true,
        ];

        return $form_fields;
    }

    public function save_attachment_fields(array $post, array $attachment): array
    {
        $attachment_id = absint($post['ID'] ?? 0);
        if (! $attachment_id || ! Meta::is_audio_attachment($attachment_id)) {
            return $post;
        }

        if (isset($attachment[Meta::TRANSCRIPT])) {
            $transcript = sanitize_textarea_field($attachment[Meta::TRANSCRIPT]);
            update_post_meta($attachment_id, Meta::TRANSCRIPT, $transcript);
            update_post_meta($attachment_id, Meta::TRANSCRIPT_UPDATED, current_time('mysql'));
            $latest = $this->transcripts->get_latest_for_attachment($attachment_id);
            if (null !== $latest) {
                $this->transcripts->update((int) $latest['id'], ['transcript_text' => $transcript]);
            } elseif ('' !== trim($transcript)) {
                $this->transcripts->insert([
                    'attachment_id' => $attachment_id,
                    'transcript_text' => $transcript,
                    'metadata_json' => wp_json_encode(['source' => 'manual_edit']),
                ]);
            }
        }

        if (isset($attachment[Meta::EXCERPT])) {
            $excerpt = sanitize_textarea_field($attachment[Meta::EXCERPT]);
            update_post_meta($attachment_id, Meta::EXCERPT, $excerpt);
            update_post_meta($attachment_id, Meta::EXCERPT_UPDATED, current_time('mysql'));
            $this->outputs->insert([
                'attachment_id' => $attachment_id,
                'output_type' => 'excerpt',
                'prompt_type' => 'manual_edit',
                'output_text' => $excerpt,
                'metadata_json' => wp_json_encode(['source' => 'manual_edit']),
            ]);
        }

        if (isset($attachment[Meta::TOPICS])) {
            update_post_meta($attachment_id, Meta::TOPICS, sanitize_textarea_field($attachment[Meta::TOPICS]));
            update_post_meta($attachment_id, Meta::TOPICS_UPDATED, current_time('mysql'));
        }

        return $post;
    }

    public function row_actions(array $actions, \WP_Post $post): array
    {
        if (! Meta::is_audio_attachment($post->ID)) {
            return $actions;
        }

        $transcribe_url = wp_nonce_url(admin_url('admin-post.php?action=wpab_transcribe&attachment_id=' . $post->ID), 'wpab_transcribe_' . $post->ID);
        $actions['wpab_transcribe'] = '<a href="' . esc_url($transcribe_url) . '">' . esc_html__('Transcribe', 'wp-audio-buddy') . '</a>';

        if (Meta::has_transcript($post->ID)) {
            $excerpt_url = wp_nonce_url(admin_url('admin-post.php?action=wpab_excerpt&attachment_id=' . $post->ID), 'wpab_excerpt_' . $post->ID);
            $actions['wpab_excerpt'] = '<a href="' . esc_url($excerpt_url) . '">' . esc_html__('Generate Excerpt', 'wp-audio-buddy') . '</a>';
        }

        return $actions;
    }

    public function handle_transcribe(): void
    {
        $this->guard();
        $attachment_id = absint($_GET['attachment_id'] ?? 0);
        check_admin_referer('wpab_transcribe_' . $attachment_id);

        $this->queue->enqueue_transcription($attachment_id);
        $this->logger->info('manual_transcribe', 'Manual transcription requested from media UI.', $attachment_id);
        wp_safe_redirect(wp_get_referer() ?: admin_url('upload.php'));
        exit;
    }

    public function handle_excerpt(): void
    {
        $this->guard();
        $attachment_id = absint($_GET['attachment_id'] ?? 0);
        check_admin_referer('wpab_excerpt_' . $attachment_id);

        $this->queue->enqueue_excerpt($attachment_id);
        $this->logger->info('manual_excerpt', 'Manual excerpt requested from media UI.', $attachment_id);
        wp_safe_redirect(wp_get_referer() ?: admin_url('upload.php'));
        exit;
    }

    private function guard(): void
    {
        if (! current_user_can('upload_files')) {
            wp_die(esc_html__('Permission denied.', 'wp-audio-buddy'));
        }
    }

    private function latest_transcript_text(int $attachment_id): string
    {
        $row = $this->transcripts->get_latest_for_attachment($attachment_id);
        if (null !== $row && '' !== trim((string) ($row['transcript_text'] ?? ''))) {
            return (string) $row['transcript_text'];
        }

        return (string) get_post_meta($attachment_id, Meta::TRANSCRIPT, true);
    }

    private function latest_excerpt_text(int $attachment_id): string
    {
        $row = $this->outputs->get_latest_for_attachment($attachment_id, 'excerpt');
        if (null !== $row && '' !== trim((string) ($row['output_text'] ?? ''))) {
            return (string) $row['output_text'];
        }

        return (string) get_post_meta($attachment_id, Meta::EXCERPT, true);
    }

    /**
     * Map custom table job statuses to user-friendly display labels.
     */
    private function map_job_status_for_display(string $job_status): string
    {
        return match ($job_status) {
            'queued' => 'queued',
            'running' => 'running',
            'waiting_for_worker' => 'running',
            'completed' => 'done',
            'failed' => 'error',
            'retryable' => 'error',
            'cancelled' => 'error',
            default => $job_status,
        };
    }
}
