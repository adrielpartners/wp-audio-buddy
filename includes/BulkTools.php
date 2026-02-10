<?php

if (! defined('ABSPATH')) {
    exit;
}

final class WPAB_Bulk_Tools
{
    public function __construct(private WPAB_Queue $queue)
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_post_wpab_bulk_transcribe', [$this, 'bulk_transcribe']);
        add_action('admin_post_wpab_bulk_excerpt', [$this, 'bulk_excerpt']);
    }

    public function menu(): void
    {
        add_menu_page('WP Audio Buddy', 'WP Audio Buddy', 'manage_options', 'wpab-bulk-tools', [$this, 'render'], 'dashicons-format-audio', 81);
        add_submenu_page('wpab-bulk-tools', 'Bulk Tools', 'Bulk Tools', 'manage_options', 'wpab-bulk-tools', [$this, 'render']);
    }

    public function render(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $counts = $this->counts();
        ?>
        <div class="wrap wpab-bulk-tools">
            <h1><?php esc_html_e('WP Audio Buddy Bulk Tools', 'wp-audio-buddy'); ?></h1>
            <p>
                <a class="button button-primary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=wpab_bulk_transcribe'), 'wpab_bulk_transcribe')); ?>"><?php esc_html_e('Queue transcription for all un-transcribed audio attachments', 'wp-audio-buddy'); ?></a>
            </p>
            <p>
                <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=wpab_bulk_excerpt'), 'wpab_bulk_excerpt')); ?>"><?php esc_html_e('Queue excerpt generation for all attachments with transcripts', 'wp-audio-buddy'); ?></a>
            </p>

            <h2><?php esc_html_e('Counters', 'wp-audio-buddy'); ?></h2>
            <ul>
                <li><strong><?php esc_html_e('Total audio files:', 'wp-audio-buddy'); ?></strong> <?php echo esc_html((string) $counts['audio']); ?></li>
                <li><strong><?php esc_html_e('Queued:', 'wp-audio-buddy'); ?></strong> <?php echo esc_html((string) $counts['queued']); ?></li>
                <li><strong><?php esc_html_e('Completed:', 'wp-audio-buddy'); ?></strong> <?php echo esc_html((string) $counts['completed']); ?></li>
                <li><strong><?php esc_html_e('Errors:', 'wp-audio-buddy'); ?></strong> <?php echo esc_html((string) $counts['errors']); ?></li>
            </ul>
        </div>
        <?php
    }

    public function bulk_transcribe(): void
    {
        $this->guard('wpab_bulk_transcribe');

        $attachments = get_posts([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => -1,
            'post_mime_type' => ['audio/mpeg', 'audio/mp3', 'audio/mp4', 'audio/x-m4a', 'audio/wav', 'audio/x-wav'],
            'fields' => 'ids',
            'meta_query' => [
                'relation' => 'OR',
                [
                    'key' => WPAB_Meta::TRANSCRIPT,
                    'compare' => 'NOT EXISTS',
                ],
                [
                    'key' => WPAB_Meta::TRANSCRIPT,
                    'value' => '',
                    'compare' => '=',
                ],
            ],
        ]);

        foreach ($attachments as $id) {
            $this->queue->enqueue_transcription((int) $id);
        }

        wp_safe_redirect(admin_url('admin.php?page=wpab-bulk-tools'));
        exit;
    }

    public function bulk_excerpt(): void
    {
        $this->guard('wpab_bulk_excerpt');

        $attachments = get_posts([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => -1,
            'post_mime_type' => ['audio/mpeg', 'audio/mp3', 'audio/mp4', 'audio/x-m4a', 'audio/wav', 'audio/x-wav'],
            'fields' => 'ids',
            'meta_query' => [
                [
                    'key' => WPAB_Meta::TRANSCRIPT,
                    'value' => '',
                    'compare' => '!=',
                ],
            ],
        ]);

        foreach ($attachments as $id) {
            $this->queue->enqueue_excerpt((int) $id);
        }

        wp_safe_redirect(admin_url('admin.php?page=wpab-bulk-tools'));
        exit;
    }

    private function counts(): array
    {
        global $wpdb;

        $audio = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='attachment' AND post_mime_type LIKE 'audio/%'");
        $queued = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key IN (%s,%s) AND meta_value='queued'", WPAB_Meta::TRANSCRIPT_STATUS, WPAB_Meta::EXCERPT_STATUS));
        $completed = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key IN (%s,%s) AND meta_value='done'", WPAB_Meta::TRANSCRIPT_STATUS, WPAB_Meta::EXCERPT_STATUS));
        $errors = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key IN (%s,%s) AND meta_value='error'", WPAB_Meta::TRANSCRIPT_STATUS, WPAB_Meta::EXCERPT_STATUS));

        return compact('audio', 'queued', 'completed', 'errors');
    }

    private function guard(string $nonce_action): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Permission denied.', 'wp-audio-buddy'));
        }

        check_admin_referer($nonce_action);
    }
}
