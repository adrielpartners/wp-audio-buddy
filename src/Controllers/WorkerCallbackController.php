<?php

declare(strict_types=1);

namespace AdrielPartners\WpAudioBuddy\Controllers;

use AdrielPartners\WpAudioBuddy\Data\JobRepository;
use AdrielPartners\WpAudioBuddy\Data\LoggerRepository;
use AdrielPartners\WpAudioBuddy\Security\SignatureService;
use AdrielPartners\WpAudioBuddy\Services\TranscriptionService;

if (! defined('ABSPATH')) {
    exit;
}

final class WorkerCallbackController
{
    private const TIMESTAMP_TOLERANCE = 300; // 5 minutes

    public function __construct(
        private SettingsController $settings,
        private LoggerRepository $logger,
        private TranscriptionService $transcription,
        private JobRepository $jobs
    ) {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes(): void
    {
        register_rest_route('wp-audio-buddy/v1', '/transcription-callback', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_callback'],
            // HMAC signature verification is the authentication mechanism.
            // WordPress cookie auth is not used for this endpoint because
            // the worker is an external service, not a logged-in admin.
            'permission_callback' => '__return_true',
        ]);
    }

    public function handle_callback(\WP_REST_Request $request): \WP_REST_Response
    {
        $raw = (string) $request->get_body();
        $header_sig = (string) $request->get_header('x-wpab-signature');
        $secret = (string) $this->settings->get('worker_shared_secret', '');

        if ('' === $secret) {
            return new \WP_REST_Response(['error' => 'worker_shared_secret_missing'], 401);
        }

        if (! SignatureService::verify($raw, $secret, $header_sig)) {
            $this->logger->error('worker_callback', 'Invalid worker callback signature.');
            return new \WP_REST_Response(['error' => 'invalid_signature'], 401);
        }

        $data = json_decode($raw, true);
        if (! is_array($data)) {
            return new \WP_REST_Response(['error' => 'invalid_json'], 400);
        }

        // --- Timestamp tolerance ---
        $timestamp = isset($data['timestamp']) ? (int) $data['timestamp'] : 0;
        if ($timestamp > 0 && abs(time() - $timestamp) > self::TIMESTAMP_TOLERANCE) {
            $this->logger->error('worker_callback', 'Callback timestamp is outside tolerance window.', null, [
                'callback_time' => $timestamp,
                'tolerance' => self::TIMESTAMP_TOLERANCE,
            ]);
            return new \WP_REST_Response(['error' => 'stale_timestamp'], 403);
        }

        // --- Site ID validation ---
        $expected_site_id = (string) $this->settings->get('worker_site_id', '');
        if ('' !== $expected_site_id) {
            $callback_site_id = (string) ($data['site_id'] ?? '');
            if ($callback_site_id !== $expected_site_id) {
                $this->logger->error('worker_callback', 'Callback site ID mismatch.', null, [
                    'expected' => $expected_site_id,
                    'received' => $callback_site_id,
                ]);
                return new \WP_REST_Response(['error' => 'site_id_mismatch'], 403);
            }
        }

        // --- Find the local job ---
        $job = null;
        $job_uuid = (string) ($data['job_uuid'] ?? '');
        if ('' !== $job_uuid) {
            $job = $this->jobs->get_by_uuid($job_uuid);
        }

        $attachment_id = absint($data['attachment_id'] ?? 0);
        if (null === $job) {
            // Fall back to attachment-based lookup if no job_uuid in callback.
            $job = $this->jobs->get_latest_for_attachment($attachment_id);
        }

        if (null === $job) {
            return new \WP_REST_Response(['error' => 'job_not_found'], 404);
        }

        // --- Validate attachment ID matches the found job ---
        $job_attachment_id = (int) ($job['attachment_id'] ?? 0);
        if ($attachment_id <= 0 || $job_attachment_id !== $attachment_id) {
            return new \WP_REST_Response(['error' => 'attachment_id_mismatch'], 400);
        }

        // --- Reject callbacks for terminal-state jobs ---
        $terminal_states = ['completed', 'failed', 'cancelled'];
        if (in_array($job['status'] ?? '', $terminal_states, true)) {
            $this->logger->info('worker_callback', 'Callback ignored — job already in terminal state.', $attachment_id, [
                'job_id' => $job['id'],
                'status' => $job['status'],
            ]);
            return new \WP_REST_Response(['ok' => true, 'status' => 'already_' . $job['status']], 200);
        }

        $job_id = (int) $job['id'];

        // --- Process the callback ---
        $status = sanitize_text_field((string) ($data['status'] ?? 'done'));
        if ('error' === $status) {
            $message = sanitize_text_field((string) ($data['error'] ?? 'Worker transcription failed.'));
            $this->transcription->fail($attachment_id, $message);
            $this->jobs->update($job_id, [
                'status' => 'failed',
                'failed_at' => current_time('mysql'),
                'error_message' => $message,
                'error_code' => 'worker_failed',
            ]);
            $this->logger->error('worker_callback', $message, $attachment_id);
            return new \WP_REST_Response(['ok' => true], 200);
        }

        $transcript = trim((string) ($data['transcript'] ?? ''));
        if ('' === $transcript) {
            $this->transcription->fail($attachment_id, 'Worker callback missing transcript.');
            return new \WP_REST_Response(['error' => 'missing_transcript'], 400);
        }

        $model = sanitize_text_field((string) ($data['model'] ?? $this->settings->get('transcription_model', 'gpt-4o-mini-transcribe')));
        $seconds = isset($data['seconds']) ? (int) $data['seconds'] : null;

        $this->transcription->save_final_transcript($attachment_id, $transcript, $model, $seconds);

        $this->jobs->update($job_id, [
            'status' => 'completed',
            'completed_at' => current_time('mysql'),
        ]);

        $minutes = max(0, (float) ($seconds ?? 0) / 60);
        $existing = (float) get_option('wpab_total_minutes_transcribed', 0);
        update_option('wpab_total_minutes_transcribed', round($existing + $minutes, 4), false);

        $this->logger->info('worker_callback', 'Worker transcript callback processed.', $attachment_id, [
            'job_id' => $job_id,
            'seconds' => $seconds,
            'model' => $model,
        ]);

        return new \WP_REST_Response(['ok' => true], 200);
    }
}