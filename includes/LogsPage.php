<?php

if (! defined('ABSPATH')) {
    exit;
}

final class WPAB_Logs_Page
{
    public function __construct(private WPAB_Logger $logger)
    {
        add_action('admin_post_wpab_clear_logs', [$this, 'clear_logs']);
    }

    public function register_menu(string $parent_slug): void
    {
        add_submenu_page($parent_slug, 'View Logs', 'View Logs', 'manage_options', 'wpab-logs', [$this, 'render']);
    }

    public function render(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $rows = $this->logger->latest(300);
        ?>
        <div class="wrap wpab-logs">
            <h1><?php esc_html_e('WP Audio Buddy Logs', 'wp-audio-buddy'); ?></h1>
            <p>
                <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=wpab_clear_logs'), 'wpab_clear_logs')); ?>"><?php esc_html_e('Clear Logs', 'wp-audio-buddy'); ?></a>
            </p>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Date', 'wp-audio-buddy'); ?></th>
                        <th><?php esc_html_e('Level', 'wp-audio-buddy'); ?></th>
                        <th><?php esc_html_e('Operation', 'wp-audio-buddy'); ?></th>
                        <th><?php esc_html_e('Attachment', 'wp-audio-buddy'); ?></th>
                        <th><?php esc_html_e('Message', 'wp-audio-buddy'); ?></th>
                        <th><?php esc_html_e('Context', 'wp-audio-buddy'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($rows)) : ?>
                    <tr><td colspan="6"><?php esc_html_e('No log entries yet.', 'wp-audio-buddy'); ?></td></tr>
                <?php else :
                    foreach ($rows as $row) : ?>
                        <tr>
                            <td><?php echo esc_html((string) $row['created_at']); ?></td>
                            <td><span class="wpab-log-level wpab-log-level-<?php echo esc_attr((string) $row['level']); ?>"><?php echo esc_html(strtoupper((string) $row['level'])); ?></span></td>
                            <td><?php echo esc_html((string) $row['operation']); ?></td>
                            <td><?php echo esc_html((string) ($row['attachment_id'] ?: '-')); ?></td>
                            <td><?php echo esc_html((string) $row['message']); ?></td>
                            <td><code><?php echo esc_html((string) ($row['context'] ?: '')); ?></code></td>
                        </tr>
                    <?php endforeach;
                endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public function clear_logs(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Permission denied.', 'wp-audio-buddy'));
        }

        check_admin_referer('wpab_clear_logs');

        global $wpdb;
        $wpdb->query('TRUNCATE TABLE ' . $wpdb->prefix . WPAB_Logger::TABLE);

        wp_safe_redirect(admin_url('admin.php?page=wpab-logs'));
        exit;
    }
}
