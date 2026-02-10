<?php

if (! defined('ABSPATH')) {
    exit;
}

final class WPAB_Media_UI
{
    public function __construct(private WPAB_Settings $settings, private WPAB_Queue $queue)
    {
        add_action('add_meta_boxes_attachment', [$this, 'meta_box']);
        add_filter('media_row_actions', [$this, 'row_actions'], 10, 2);
        add_action('admin_post_wpab_transcribe', [$this, 'handle_transcribe']);
        add_action('admin_post_wpab_excerpt', [$this, 'handle_excerpt']);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
    }

    public function assets(): void
    {
        wp_enqueue_style('wpab-admin', WPAB_URL . 'assets/admin.css', [], WPAB_VERSION);
    }

    public function meta_box(): void
    {
        add_meta_box('wpab_attachment_box', __('WP Audio Buddy', 'wp-audio-buddy'), [$this, 'render_meta_box'], 'attachment', 'side');
    }

    public function render_meta_box(WP_Post $post): void
    {
        if (! WPAB_Meta::is_audio_attachment($post->ID)) {
            echo '<p>' . esc_html__('Only audio attachments are supported.', 'wp-audio-buddy') . '</p>';
            return;
        }

        $status = WPAB_Meta::transcript_status($post->ID);
        $excerpt_status = WPAB_Meta::excerpt_status($post->ID);
        $err = (string) get_post_meta($post->ID, WPAB_Meta::TRANSCRIPT_ERROR, true);

        echo '<p><strong>' . esc_html__('Transcription status:', 'wp-audio-buddy') . '</strong> ' . esc_html($status) . '</p>';
        echo '<p><strong>' . esc_html__('Excerpt status:', 'wp-audio-buddy') . '</strong> ' . esc_html($excerpt_status) . '</p>';

        $transcribe_url = wp_nonce_url(admin_url('admin-post.php?action=wpab_transcribe&attachment_id=' . $post->ID), 'wpab_transcribe_' . $post->ID);
        $excerpt_url = wp_nonce_url(admin_url('admin-post.php?action=wpab_excerpt&attachment_id=' . $post->ID), 'wpab_excerpt_' . $post->ID);

        echo '<p><a class="button button-primary wpab-action-btn" href="' . esc_url($transcribe_url) . '">' . esc_html__('Transcribe Audio', 'wp-audio-buddy') . '</a></p>';
        echo '<p><a class="button wpab-action-btn" href="' . esc_url($excerpt_url) . '">' . esc_html__('Generate Excerpt', 'wp-audio-buddy') . '</a></p>';

        if ('' !== $err) {
            echo '<div class="notice notice-error inline"><p>' . esc_html($err) . '</p></div>';
        }
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
        wp_safe_redirect(wp_get_referer() ?: admin_url('upload.php'));
        exit;
    }

    public function handle_excerpt(): void
    {
        $this->guard();
        $attachment_id = absint($_GET['attachment_id'] ?? 0);
        check_admin_referer('wpab_excerpt_' . $attachment_id);

        $this->queue->enqueue_excerpt($attachment_id);
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
