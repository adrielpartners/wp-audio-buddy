<?php

if (! defined('ABSPATH')) {
    exit;
}

final class WPAB_Media_UI
{
    public function __construct(private WPAB_Settings $settings, private WPAB_Queue $queue, private WPAB_Logger $logger)
    {
        add_filter('attachment_fields_to_edit', [$this, 'attachment_fields'], 10, 2);
        add_filter('attachment_fields_to_save', [$this, 'save_attachment_fields'], 10, 2);
        add_filter('media_row_actions', [$this, 'row_actions'], 10, 2);
        add_action('admin_post_wpab_transcribe', [$this, 'handle_transcribe']);
        add_action('admin_post_wpab_excerpt', [$this, 'handle_excerpt']);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
    }

    public function assets(): void
    {
        wp_enqueue_style('wpab-admin', WPAB_URL . 'assets/admin.css', [], WPAB_VERSION);
    }

    public function attachment_fields(array $form_fields, WP_Post $post): array
    {
        if (! WPAB_Meta::is_audio_attachment($post->ID)) {
            return $form_fields;
        }

        $status = WPAB_Meta::transcript_status($post->ID);
        $excerpt_status = WPAB_Meta::excerpt_status($post->ID);
        $err = (string) get_post_meta($post->ID, WPAB_Meta::TRANSCRIPT_ERROR, true);
        $chunk_progress = WPAB_Meta::chunk_progress_label($post->ID);

        $transcribe_url = wp_nonce_url(admin_url('admin-post.php?action=wpab_transcribe&attachment_id=' . $post->ID), 'wpab_transcribe_' . $post->ID);
        $excerpt_url = wp_nonce_url(admin_url('admin-post.php?action=wpab_excerpt&attachment_id=' . $post->ID), 'wpab_excerpt_' . $post->ID);

        $actions = '<div class="wpab-media-actions">';
        $actions .= '<p><strong>' . esc_html__('Transcription status:', 'wp-audio-buddy') . '</strong> ' . esc_html($status) . '</p>';
        if ('' !== $chunk_progress) {
            $actions .= '<p class="wpab-chunk-progress"><strong>' . esc_html__('Progress:', 'wp-audio-buddy') . '</strong> ' . esc_html($chunk_progress) . '</p>';
        }
        $actions .= '<p><strong>' . esc_html__('Excerpt status:', 'wp-audio-buddy') . '</strong> ' . esc_html($excerpt_status) . '</p>';
        $actions .= '<p><a class="button button-primary wpab-action-btn" href="' . esc_url($transcribe_url) . '">' . esc_html__('Transcribe Audio', 'wp-audio-buddy') . '</a>';
        $actions .= '<a class="button wpab-action-btn" href="' . esc_url($excerpt_url) . '">' . esc_html__('Generate Excerpt', 'wp-audio-buddy') . '</a></p>';

        if ('' !== $err) {
            $actions .= '<div class="notice notice-error inline"><p>' . esc_html($err) . '</p></div>';
        }

        $actions .= '</div>';

        $form_fields['wpab_actions'] = [
            'label' => __('WP Audio Buddy', 'wp-audio-buddy'),
            'input' => 'html',
            'html' => $actions,
            'show_in_edit' => true,
            'show_in_modal' => true,
        ];

        $form_fields[WPAB_Meta::TRANSCRIPT] = [
            'label' => __('Transcription', 'wp-audio-buddy'),
            'input' => 'textarea',
            'value' => (string) get_post_meta($post->ID, WPAB_Meta::TRANSCRIPT, true),
            'helps' => __('Editable transcript stored on this attachment.', 'wp-audio-buddy'),
            'show_in_edit' => true,
            'show_in_modal' => true,
        ];

        $form_fields[WPAB_Meta::EXCERPT] = [
            'label' => __('Excerpt', 'wp-audio-buddy'),
            'input' => 'textarea',
            'value' => (string) get_post_meta($post->ID, WPAB_Meta::EXCERPT, true),
            'helps' => __('Editable excerpt stored on this attachment.', 'wp-audio-buddy'),
            'show_in_edit' => true,
            'show_in_modal' => true,
        ];

        return $form_fields;
    }

    public function save_attachment_fields(array $post, array $attachment): array
    {
        $attachment_id = absint($post['ID'] ?? 0);
        if (! $attachment_id || ! WPAB_Meta::is_audio_attachment($attachment_id)) {
            return $post;
        }

        if (isset($attachment[WPAB_Meta::TRANSCRIPT])) {
            update_post_meta($attachment_id, WPAB_Meta::TRANSCRIPT, sanitize_textarea_field($attachment[WPAB_Meta::TRANSCRIPT]));
            update_post_meta($attachment_id, WPAB_Meta::TRANSCRIPT_UPDATED, current_time('mysql'));
        }

        if (isset($attachment[WPAB_Meta::EXCERPT])) {
            update_post_meta($attachment_id, WPAB_Meta::EXCERPT, sanitize_textarea_field($attachment[WPAB_Meta::EXCERPT]));
            update_post_meta($attachment_id, WPAB_Meta::EXCERPT_UPDATED, current_time('mysql'));
        }

        return $post;
    }

    public function row_actions(array $actions, WP_Post $post): array
    {
        if (! WPAB_Meta::is_audio_attachment($post->ID)) {
            return $actions;
        }

        $transcribe_url = wp_nonce_url(admin_url('admin-post.php?action=wpab_transcribe&attachment_id=' . $post->ID), 'wpab_transcribe_' . $post->ID);
        $actions['wpab_transcribe'] = '<a href="' . esc_url($transcribe_url) . '">' . esc_html__('Transcribe', 'wp-audio-buddy') . '</a>';

        if (WPAB_Meta::has_transcript($post->ID)) {
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
}
