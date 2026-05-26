<?php

declare(strict_types=1);

namespace AdrielPartners\WpAudioBuddy\Data;

if (! defined('ABSPATH')) {
    exit;
}

final class Schema
{
    public const DB_VERSION = '1.1.0';
    public const DB_VERSION_OPTION = 'wpab_db_version';

    public const TABLE_JOBS = 'wpab_jobs';
    public const TABLE_TRANSCRIPTS = 'wpab_transcripts';
    public const TABLE_GENERATED_OUTPUTS = 'wpab_generated_outputs';

    /**
     * Create or update custom tables. Runs on plugin activation.
     */
    public function install(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();

        $jobs_table = $wpdb->prefix . self::TABLE_JOBS;
        $jobs_sql = "CREATE TABLE {$jobs_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            job_uuid VARCHAR(36) NOT NULL,
            attachment_id BIGINT UNSIGNED NOT NULL,
            operation VARCHAR(50) NOT NULL,
            processing_mode VARCHAR(20) NOT NULL DEFAULT 'local',
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            source VARCHAR(20) NOT NULL DEFAULT 'manual',
            attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
            error_code VARCHAR(50) NULL,
            error_message TEXT NULL,
            worker_job_id VARCHAR(100) NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            started_at DATETIME NULL,
            completed_at DATETIME NULL,
            failed_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY job_uuid (job_uuid),
            KEY attachment_id (attachment_id),
            KEY status (status),
            KEY operation (operation),
            KEY created_at (created_at)
        ) {$charset};";

        $transcripts_table = $wpdb->prefix . self::TABLE_TRANSCRIPTS;
        $transcripts_sql = "CREATE TABLE {$transcripts_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            attachment_id BIGINT UNSIGNED NOT NULL,
            job_id BIGINT UNSIGNED NULL,
            transcript_text LONGTEXT NOT NULL,
            segments_json LONGTEXT NULL,
            metadata_json LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY attachment_id (attachment_id),
            KEY job_id (job_id),
            KEY created_at (created_at)
        ) {$charset};";

        dbDelta($jobs_sql);
        dbDelta($transcripts_sql);

        $outputs_table = $wpdb->prefix . self::TABLE_GENERATED_OUTPUTS;
        $outputs_sql = "CREATE TABLE {$outputs_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            attachment_id BIGINT UNSIGNED NOT NULL,
            job_id BIGINT UNSIGNED NULL,
            output_type VARCHAR(20) NOT NULL DEFAULT 'excerpt',
            prompt_type VARCHAR(50) NULL,
            output_text LONGTEXT NOT NULL,
            metadata_json LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY attachment_id (attachment_id),
            KEY job_id (job_id),
            KEY output_type (output_type),
            KEY created_at (created_at)
        ) {$charset};";
        dbDelta($outputs_sql);

        update_option(self::DB_VERSION_OPTION, self::DB_VERSION, false);
    }

    /**
     * Drop all plugin custom tables. Called on uninstall when deletion is enabled.
     */
    public function uninstall(): void
    {
        global $wpdb;

        $wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . self::TABLE_JOBS);
        $wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . self::TABLE_TRANSCRIPTS);
        $wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . self::TABLE_GENERATED_OUTPUTS);

        delete_option(self::DB_VERSION_OPTION);
    }

    /**
     * Check whether the database schema needs an update.
     */
    public function needs_update(): bool
    {
        return get_option(self::DB_VERSION_OPTION, '0') !== self::DB_VERSION;
    }
}