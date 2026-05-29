<?php

declare(strict_types=1);

namespace AdrielPartners\WpAudioBuddy\Data;

if (! defined('ABSPATH')) {
    exit;
}

final class GeneratedOutputRepository
{
    /**
     * Insert a new generated output record. Returns the new row ID.
     */
    public function insert(array $data): int
    {
        global $wpdb;

        $defaults = [
            'attachment_id' => 0,
            'job_id' => null,
            'output_type' => 'excerpt',
            'prompt_type' => null,
            'output_text' => '',
            'metadata_json' => null,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ];

        $data = wp_parse_args($data, $defaults);

        $wpdb->insert(
            $wpdb->prefix . Schema::TABLE_GENERATED_OUTPUTS,
            [
                'attachment_id' => (int) $data['attachment_id'],
                'job_id' => $data['job_id'] !== null ? (int) $data['job_id'] : null,
                'output_type' => $data['output_type'],
                'prompt_type' => $data['prompt_type'],
                'output_text' => $data['output_text'],
                'metadata_json' => $data['metadata_json'],
                'created_at' => $data['created_at'],
                'updated_at' => $data['updated_at'],
            ],
            ['%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s']
        );

        return (int) $wpdb->insert_id;
    }

    /**
     * Get the latest output for an attachment by type.
     */
    public function get_latest_for_attachment(int $attachment_id, string $output_type = 'excerpt'): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}" . Schema::TABLE_GENERATED_OUTPUTS
                    . ' WHERE attachment_id = %d AND output_type = %s ORDER BY created_at DESC LIMIT 1',
                $attachment_id,
                $output_type
            ),
            ARRAY_A
        );

        return $row ?: null;
    }

    /**
     * Delete all outputs for a specific attachment.
     */
    public function delete_by_attachment(int $attachment_id): int
    {
        global $wpdb;
        return (int) $wpdb->delete(
            $wpdb->prefix . Schema::TABLE_GENERATED_OUTPUTS,
            ['attachment_id' => $attachment_id],
            ['%d']
        );
    }
}