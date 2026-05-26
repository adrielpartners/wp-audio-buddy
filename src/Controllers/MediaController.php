<?php

declare(strict_types=1);

namespace AdrielPartners\WpAudioBuddy\Controllers;

use AdrielPartners\WpAudioBuddy\Data\JobRepository;
use AdrielPartners\WpAudioBuddy\Data\LoggerRepository;
use AdrielPartners\WpAudioBuddy\Data\Meta;
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
        private JobRepository $jobs
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
            'value' => (string) get_post_meta($post->ID, Meta::TRANSCRIPT, true),
            'helps' => $this->transcript_meta_html($post->ID, $job),
            'show_in_edit' => true,
            'show_in_modal' => true,
        ];

        $form_fields[Meta::EXCERPT] = [
            'label' => __('Excerpt', 'wp-audio-buddy'),
            'input' => 'textarea',
            'value' => (string) get_post_meta($post->ID, Meta::EXCERPT, true),
            'helps' => $this->excerpt_meta_html($post->ID),
            'show_in_edit' => true,
            'show_in_modal' => true,
        ];

        // Inline copy-to-clipboard JS for the attachment edit/modal screens.
        $form_fields['wpab_copy_buttons'] = [
            'label' => __('Copy', 'wp-audio-buddy'),
            'input' => 'html',
            'html' => $this->copy_buttons_html($post->ID),
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
            update_post_meta($attachment_id, Meta::TRANSCRIPT, sanitize_textarea_field($attachment[Meta::TRANSCRIPT]));
            update_post_meta($attachment_id, Meta::TRANSCRIPT_UPDATED, current_time('mysql'));
        }

        if (isset($attachment[Meta::EXCERPT])) {
            update_post_meta($attachment_id, Meta::EXCERPT, sanitize_textarea_field($attachment[Meta::EXCERPT]));
            update_post_meta($attachment_id, Meta::EXCERPT_UPDATED, current_time('mysql'));
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

    /**
     * Build metadata HTML shown below the transcript textarea.
     */
    private function transcript_meta_html(int $attachment_id, ?array $job): string
    {
        $parts = [];

        $model = (string) get_post_meta($attachment_id, Meta::TRANSCRIPT_MODEL, true);
        if ('' !== $model) {
            $parts[] = sprintf(
                '<span class="wpab-meta-item">Model: %s</span>',
                esc_html($model)
            );
        }

        $seconds = (int) get_post_meta($attachment_id, Meta::TRANSCRIPT_SECONDS, true);
        if ($seconds > 0) {
            $minutes = round($seconds / 60, 1);
            $parts[] = sprintf(
                '<span class="wpab-meta-item">Duration: %s min</span>',
                esc_html((string) $minutes)
            );
        }

        $updated = (string) get_post_meta($attachment_id, Meta::TRANSCRIPT_UPDATED, true);
        if ('' !== $updated) {
            $parts[] = sprintf(
                '<span class="wpab-meta-item">Generated: %s</span>',
                esc_html($updated)
            );
        }

        if (null !== $job) {
            $job_status_display = esc_html((string) ($job['status'] ?? ''));
            $parts[] = sprintf(
                '<span class="wpab-meta-item">Job: #%d (%s)</span>',
                (int) ($job['id'] ?? 0),
                $job_status_display
            );
        }

        $html = '<div class="wpab-meta">' . implode(' &middot; ', $parts) . '</div>';
        $html .= '<p class="description">' . esc_html__('Editable transcript stored on this attachment.', 'wp-audio-buddy') . '</p>';

        // Collapse very long transcripts for readability.
        $text = (string) get_post_meta($attachment_id, Meta::TRANSCRIPT, true);
        if (function_exists('mb_strlen') && mb_strlen($text) > 5000) {
            $html .= '<details class="wpab-transcript-collapse"><summary>' . esc_html__('Full transcript (' . mb_strlen($text) . ' characters)', 'wp-audio-buddy') . '</summary><p class="description">' . esc_html__('The full text is shown in the textarea above.', 'wp-audio-buddy') . '</p></details>';
        }

        return $html;
    }

    /**
     * Build metadata HTML shown below the excerpt textarea.
     */
    private function excerpt_meta_html(int $attachment_id): string
    {
        $parts = [];

        $model = (string) get_post_meta($attachment_id, Meta::EXCERPT_MODEL, true);
        if ('' !== $model) {
            $parts[] = sprintf(
                '<span class="wpab-meta-item">Model: %s</span>',
                esc_html($model)
            );
        }

        $prompt_type = (string) get_post_meta($attachment_id, Meta::EXCERPT_PROMPT_TYPE, true);
        if ('' !== $prompt_type) {
            $parts[] = sprintf(
                '<span class="wpab-meta-item">Type: %s</span>',
                esc_html($prompt_type)
            );
        }

        $updated = (string) get_post_meta($attachment_id, Meta::EXCERPT_UPDATED, true);
        if ('' !== $updated) {
            $parts[] = sprintf(
                '<span class="wpab-meta-item">Generated: %s</span>',
                esc_html($updated)
            );
        }

        if (! empty($parts)) {
            return '<div class="wpab-meta">' . implode(' &middot; ', $parts) . '</div>'
                . '<p class="description">' . esc_html__('Editable excerpt stored on this attachment.', 'wp-audio-buddy') . '</p>';
        }

        return esc_html__('Editable excerpt stored on this attachment.', 'wp-audio-buddy');
    }

    /**
     * Build copy-to-clipboard buttons for transcript and excerpt.
     */
    private function copy_buttons_html(int $attachment_id): string
    {
        $has_transcript = '' !== trim((string) get_post_meta($attachment_id, Meta::TRANSCRIPT, true));
        $has_excerpt = '' !== trim((string) get_post_meta($attachment_id, Meta::EXCERPT, true));

        $html = '<div class="wpab-copy-area">';

        if ($has_transcript) {
            $html .= '<button type="button" class="button wpab-copy-btn" data-attachment="' . esc_attr((string) $attachment_id) . '" data-field="' . esc_attr(Meta::TRANSCRIPT) . '">'
                . esc_html__('Copy Transcription', 'wp-audio-buddy') . '</button> ';
        }

        if ($has_excerpt) {
            $html .= '<button type="button" class="button wpab-copy-btn" data-attachment="' . esc_attr((string) $attachment_id) . '" data-field="' . esc_attr(Meta::EXCERPT) . '">'
                . esc_html__('Copy Excerpt', 'wp-audio-buddy') . '</button>';
        }

        if (! $has_transcript && ! $has_excerpt) {
            $html .= '<span class="description">' . esc_html__('Generate a transcript or excerpt first.', 'wp-audio-buddy') . '</span>';
        }

        $html .= '<span class="wpab-copy-message" aria-live="polite" style="margin-left:6px"></span>';
        $html .= '</div>';

        $html .= <<<'JS'
<script>
(function(){
    var btns = document.querySelectorAll('.wpab-copy-btn');
    btns.forEach(function(btn){
        btn.addEventListener('click', async function(){
            var fieldName = btn.getAttribute('data-field');
            var textarea = document.querySelector('textarea[name*="' + fieldName + '"]');
            if (!textarea) {
                var compat = document.querySelector('.compat-field-' + fieldName + ' textarea');
                if (compat) textarea = compat;
            }
            var msgEl = btn.parentNode.querySelector('.wpab-copy-message');
            if (!textarea || !textarea.value) {
                if (msgEl) msgEl.textContent = 'Nothing to copy.';
                return;
            }
            try {
                await navigator.clipboard.writeText(textarea.value);
                if (msgEl) msgEl.textContent = 'Copied!';
            } catch(e) {
                if (msgEl) msgEl.textContent = 'Copy failed. Select and copy manually.';
            }
        });
    });
})();
</script>
JS;

        return $html;
    }

    private function guard(): void
    {
        if (! current_user_can('upload_files')) {
            wp_die(esc_html__('Permission denied.', 'wp-audio-buddy'));
        }
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