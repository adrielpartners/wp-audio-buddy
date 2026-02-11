<?php

if (! defined('ABSPATH')) {
    exit;
}

final class WPAB_Logger
{
    public const TABLE = 'wpab_logs';

    public function create_table(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = $wpdb->prefix . self::TABLE;
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at DATETIME NOT NULL,
            level VARCHAR(20) NOT NULL,
            operation VARCHAR(120) NOT NULL,
            attachment_id BIGINT UNSIGNED NULL,
            message TEXT NOT NULL,
            context LONGTEXT NULL,
            PRIMARY KEY (id),
            KEY level (level),
            KEY operation (operation),
            KEY attachment_id (attachment_id),
            KEY created_at (created_at)
        ) {$charset};";

        dbDelta($sql);
    }

    public function info(string $operation, string $message, ?int $attachment_id = null, array $context = []): void
    {
        $this->log('info', $operation, $message, $attachment_id, $context);
    }

    public function error(string $operation, string $message, ?int $attachment_id = null, array $context = []): void
    {
        $this->log('error', $operation, $message, $attachment_id, $context);
    }

    public function log(string $level, string $operation, string $message, ?int $attachment_id = null, array $context = []): void
    {
        global $wpdb;

        $wpdb->insert(
            $wpdb->prefix . self::TABLE,
            [
                'created_at' => current_time('mysql'),
                'level' => sanitize_key($level),
                'operation' => sanitize_text_field($operation),
                'attachment_id' => $attachment_id,
                'message' => $message,
                'context' => empty($context) ? null : wp_json_encode($context),
            ],
            ['%s', '%s', '%s', '%d', '%s', '%s']
        );
    }

    public function latest(int $limit = 200): array
    {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $limit = max(1, min(1000, $limit));

        return (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit), ARRAY_A);
    }
}
