<?php

declare(strict_types=1);

namespace AdrielPartners\WpAudioBuddy\Data;

if (! defined('ABSPATH')) {
    exit;
}

final class JobRepository
{
    /**
     * Insert a new job record. Returns the new row ID.
     */
    public function insert(array $data): int
    {
        global $wpdb;

        $defaults = [
            'job_uuid' => wp_generate_uuid4(),
            'attachment_id' => 0,
            'operation' => 'transcribe',
            'processing_mode' => 'local',
            'status' => 'pending',
            'source' => 'manual',
            'attempt_count' => 0,
            'error_code' => null,
            'error_message' => null,
            'worker_job_id' => null,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
            'started_at' => null,
            'completed_at' => null,
            'failed_at' => null,
        ];

        $data = wp_parse_args($data, $defaults);

        $wpdb->insert(
            $wpdb->prefix . Schema::TABLE_JOBS,
            [
                'job_uuid' => $data['job_uuid'],
                'attachment_id' => (int) $data['attachment_id'],
                'operation' => $data['operation'],
                'processing_mode' => $data['processing_mode'],
                'status' => $data['status'],
                'source' => $data['source'],
                'attempt_count' => (int) $data['attempt_count'],
                'error_code' => $data['error_code'],
                'error_message' => $data['error_message'],
                'worker_job_id' => $data['worker_job_id'],
                'created_at' => $data['created_at'],
                'updated_at' => $data['updated_at'],
                'started_at' => $data['started_at'],
                'completed_at' => $data['completed_at'],
                'failed_at' => $data['failed_at'],
            ],
            ['%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );

        return (int) $wpdb->insert_id;
    }

    /**
     * Update an existing job record by ID.
     */
    public function update(int $id, array $data): bool
    {
        global $wpdb;

        $allowed = [
            'status', 'processing_mode', 'attempt_count', 'error_code',
            'error_message', 'worker_job_id', 'started_at', 'completed_at',
            'failed_at', 'updated_at',
        ];

        $set = [];
        $formats = [];

        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $set[$field] = $data[$field];
                $formats[] = $this->field_format($field);
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
            $wpdb->prefix . Schema::TABLE_JOBS,
            $set,
            ['id' => $id],
            $formats,
            ['%d']
        );
    }

    /**
     * Get a single job by ID.
     */
    public function get_by_id(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}" . Schema::TABLE_JOBS . ' WHERE id = %d',
                $id
            ),
            ARRAY_A
        );

        return $row ?: null;
    }

    /**
     * Get a single job by UUID.
     */
    public function get_by_uuid(string $uuid): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}" . Schema::TABLE_JOBS . ' WHERE job_uuid = %s',
                $uuid
            ),
            ARRAY_A
        );

        return $row ?: null;
    }

    /**
     * Get all jobs for a specific attachment, newest first.
     */
    public function get_by_attachment(int $attachment_id, int $limit = 10): array
    {
        global $wpdb;
        return (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}" . Schema::TABLE_JOBS . ' WHERE attachment_id = %d ORDER BY created_at DESC LIMIT %d',
                $attachment_id,
                max(1, min(100, $limit))
            ),
            ARRAY_A
        );
    }

    /**
     * Get jobs by status, newest first.
     */
    public function get_by_status(string $status, int $limit = 50): array
    {
        global $wpdb;
        return (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}" . Schema::TABLE_JOBS . ' WHERE status = %s ORDER BY created_at DESC LIMIT %d',
                $status,
                max(1, min(500, $limit))
            ),
            ARRAY_A
        );
    }

    /**
     * Get the most recent job for an attachment.
     */
    public function get_latest_for_attachment(int $attachment_id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}" . Schema::TABLE_JOBS . ' WHERE attachment_id = %d ORDER BY created_at DESC LIMIT 1',
                $attachment_id
            ),
            ARRAY_A
        );

        return $row ?: null;
    }

    /**
     * Count jobs by status.
     */
    public function count_by_status(string $status): int
    {
        global $wpdb;
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}" . Schema::TABLE_JOBS . ' WHERE status = %s',
                $status
            )
        );
    }

    /**
     * Delete a single job by ID.
     */
    public function delete(int $id): bool
    {
        global $wpdb;
        return (bool) $wpdb->delete(
            $wpdb->prefix . Schema::TABLE_JOBS,
            ['id' => $id],
            ['%d']
        );
    }

    /**
     * Update the most recent job for an attachment with the given data.
     */
    public function update_by_attachment(int $attachment_id, array $data): bool
    {
        $latest = $this->get_latest_for_attachment($attachment_id);
        if (null === $latest) {
            return false;
        }

        return $this->update((int) $latest['id'], $data);
    }

    /**
     * Delete all jobs for a specific attachment.
     */
    public function delete_by_attachment(int $attachment_id): int
    {
        global $wpdb;
        return (int) $wpdb->delete(
            $wpdb->prefix . Schema::TABLE_JOBS,
            ['attachment_id' => $attachment_id],
            ['%d']
        );
    }

    private function field_format(string $field): string
    {
        $ints = ['attachment_id', 'attempt_count'];
        return in_array($field, $ints, true) ? '%d' : '%s';
    }
}