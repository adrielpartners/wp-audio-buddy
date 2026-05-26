<?php
/**
 * WP Audio Buddy Uninstall
 *
 * Deletes plugin data only when the admin has explicitly enabled deletion
 * in Settings > Data Management.
 *
 * @package WP Audio Buddy
 */

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$settings = get_option('wpab_settings', []);

if (! empty($settings['delete_data_on_uninstall'])) {
    global $wpdb;

    // Drop custom tables.
    $wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'wpab_jobs');
    $wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'wpab_transcripts');
    $wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'wpab_generated_outputs');
    $wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'wpab_logs');

    // Remove all plugin post meta.
    $wpdb->query(
        "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE 'wpab_%'"
    );

    // Remove plugin options.
    delete_option('wpab_settings');
    delete_option('wpab_db_version');
    delete_option('wpab_total_minutes_transcribed');
}