<?php

declare(strict_types=1);

namespace AdrielPartners\WpAudioBuddy\Data;

if (! defined('ABSPATH')) {
    exit;
}

final class TranscriptRepository
{
    /**
     * Insert a new transcript record. Returns the new row ID.
     */
    public function insert(array $data): int
    {
        global $wpdb;

        $defaults = [
            'attachment_id' => 0,
            'job_id' => null,
            'transcript_text' => '',
            'segments_json' => null,
            'metadata_json' => null,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ];

        $data = wp_parse_args($data, $defaults);

        $wpdb->insert(
            $wpdb->prefix . Schema::TABLE_TRANSCRIPTS,
            [
                'attachment_id' => (int) $data['attachment_id'],
                'job_id' => $data['job_id'] !== null ? (int) $data['job_id'] : null,
                'transcript_text' => $data['transcript_text'],
                'segments_json' => $data['segments_json'],
                'metadata_json' => $data['metadata_json'],
                'created_at' => $data['created_at'],
                'updated_at' => $data['updated_at'],
            ],
            ['%d', '%d', '%s', '%s', '%s', '%s', '%s']
        );

        return (int) $wpdb->insert_id;
    }

    /**
     * Update an existing transcript record by ID.
     */
    public function update(int $id, array $data): bool
    {
        global $wpdb;

        $allowed = [
            'transcript_text', 'segments_json', 'metadata_json', 'updated_at',
        ];

        $set = [];
        $formats = [];

        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $set[$field] = $data[$field];
                $formats[] = '%s';
            }
        }

        if (empty($set)) {
            return false;
        }

        if (! isset($set['updated_at'])) {
            $set['updated_at'] = current_time('mysql');
            $formats[] = '%s';
        }

        return (bool) $wpdb->update(
            $wpdb->prefix . Schema::TABLE_TRANSCRIPTS,
            $set,
            ['id' => $id],
            $formats,
            ['%d']
        );
    }

    /**
     * Get a transcript by ID.
     */
    public function get_by_id(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}" . Schema::TABLE_TRANSCRIPTS . ' WHERE id = %d',
                $id
            ),
            ARRAY_A
        );

        return $row ?: null;
    }

    /**
     * Get the latest transcript for an attachment.
     */
    public function get_latest_for_attachment(int $attachment_id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}" . Schema::TABLE_TRANSCRIPTS . ' WHERE attachment_id = %d ORDER BY created_at DESC LIMIT 1',
                $attachment_id
            ),
            ARRAY_A
        );

        return $row ?: null;
    }

    /**
     * Get the transcript associated with a specific job.
     */
    public function get_by_job(int $job_id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}" . Schema::TABLE_TRANSCRIPTS . ' WHERE job_id = %d ORDER BY created_at DESC LIMIT 1',
                $job_id
            ),
            ARRAY_A
        );

        return $row ?: null;
    }

    /**
     * Get all transcripts for an attachment, newest first.
     */
    public function get_all_for_attachment(int $attachment_id, int $limit = 10): array
    {
        global $wpdb;
        return (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}" . Schema::TABLE_TRANSCRIPTS . ' WHERE attachment_id = %d ORDER BY created_at DESC LIMIT %d',
                $attachment_id,
                max(1, min(100, $limit))
            ),
            ARRAY_A
        );
    }

    /**
     * Delete a transcript by ID.
     */
    public function delete(int $id): bool
    {
        global $wpdb;
        return (bool) $wpdb->delete(
            $wpdb->prefix . Schema::TABLE_TRANSCRIPTS,
            ['id' => $id],
            ['%d']
        );
    }

    /**
     * Delete all transcripts for a specific attachment.
     */
    public function delete_by_attachment(int $attachment_id): int
    {
        global $wpdb;
        return (int) $wpdb->delete(
            $wpdb->prefix . Schema::TABLE_TRANSCRIPTS,
            ['attachment_id' => $attachment_id],
            ['%d']
        );
    }
}