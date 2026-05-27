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
        register_rest_route('wpab/v1', '/worker-callback', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_callback'],
            // HMAC signature verification is the authentication mechanism.
            // WordPress cookie auth is not used for this endpoint because
            // the worker is an external service, not a logged-in admin.
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('wp-audio-buddy/v1', '/transcription-callback', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_callback'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('wpab/v1', '/audio-download/(?P<attachment_id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'serve_audio'],
            'permission_callback' => '__return_true',
            'args' => [
                'attachment_id' => [
                    'validate_callback' => static fn ($value): bool => absint($value) > 0,
                ],
            ],
        ]);
    }

    public function handle_callback(\WP_REST_Request $request): \WP_REST_Response
    {
        $raw = (string) $request->get_body();
        $header_sig = (string) $request->get_header('x-wpab-signature');
        $header_timestamp = (string) $request->get_header('x-wpab-timestamp');
        $header_site_id = (string) $request->get_header('x-wpab-site-id');
        $secret = (string) $this->settings->get('worker_shared_secret', '');

        if ('' === $secret) {
            return new \WP_REST_Response(['error' => 'worker_shared_secret_missing'], 401);
        }

        if ('' === $header_sig || '' === $header_timestamp || '' === $header_site_id) {
            return new \WP_REST_Response(['error' => 'missing_signature_headers'], 401);
        }

        $timestamp = (int) $header_timestamp;
        if ($timestamp <= 0 || abs(time() - $timestamp) > self::TIMESTAMP_TOLERANCE) {
            $this->logger->error('worker_callback', 'Callback timestamp is missing or outside tolerance window.', null, [
                'callback_time' => $timestamp,
                'tolerance' => self::TIMESTAMP_TOLERANCE,
            ]);
            return new \WP_REST_Response(['error' => 'stale_timestamp'], 403);
        }

        if (! SignatureService::verify_request($raw, $secret, $header_timestamp, $header_site_id, $header_sig)) {
            $this->logger->error('worker_callback', 'Invalid worker callback signature.');
            return new \WP_REST_Response(['error' => 'invalid_signature'], 401);
        }

        $data = json_decode($raw, true);
        if (! is_array($data)) {
            return new \WP_REST_Response(['error' => 'invalid_json'], 400);
        }

        $payload_timestamp = isset($data['timestamp']) ? (int) $data['timestamp'] : 0;
        if ($payload_timestamp !== $timestamp) {
            return new \WP_REST_Response(['error' => 'timestamp_mismatch'], 403);
        }

        // --- Site ID validation ---
        $expected_site_id = (string) $this->settings->get('worker_site_id', '');
        if ('' !== $expected_site_id) {
            $callback_site_id = (string) ($data['site_id'] ?? '');
            if ($callback_site_id !== $expected_site_id || $header_site_id !== $expected_site_id) {
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
            $job = $this->jobs->get_latest_for_attachment_operation($attachment_id, 'transcribe');
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
            $this->transcription->fail($attachment_id, $message, $job_id);
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
            $this->transcription->fail($attachment_id, 'Worker callback missing transcript.', $job_id);
            return new \WP_REST_Response(['error' => 'missing_transcript'], 400);
        }

        $model = sanitize_text_field((string) ($data['model'] ?? $this->settings->get('transcription_model', 'gpt-4o-mini-transcribe')));
        $seconds = isset($data['seconds']) ? (int) $data['seconds'] : null;

        $this->transcription->save_final_transcript($attachment_id, $transcript, $model, $seconds, $job_id);

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

    public function serve_audio(\WP_REST_Request $request): \WP_REST_Response
    {
        $attachment_id = absint($request['attachment_id'] ?? 0);
        $job_uuid = sanitize_text_field((string) $request->get_param('job_uuid'));
        $expires = (int) $request->get_param('expires');
        $signature = sanitize_text_field((string) $request->get_param('signature'));
        $secret = (string) $this->settings->get('worker_shared_secret', '');

        if ($attachment_id <= 0 || '' === $job_uuid || $expires <= 0 || '' === $signature || '' === $secret) {
            return new \WP_REST_Response(['error' => 'invalid_download_request'], 400);
        }

        if (time() > $expires) {
            return new \WP_REST_Response(['error' => 'download_url_expired'], 403);
        }

        $expected = SignatureService::sign_download_url($attachment_id, $job_uuid, $expires, $secret);
        if (! hash_equals($expected, $signature)) {
            return new \WP_REST_Response(['error' => 'invalid_download_signature'], 403);
        }

        $job = $this->jobs->get_by_uuid($job_uuid);
        if (null === $job || (int) ($job['attachment_id'] ?? 0) !== $attachment_id) {
            return new \WP_REST_Response(['error' => 'job_not_found'], 404);
        }

        $file = (string) get_attached_file($attachment_id);
        if ('' === $file || ! is_readable($file)) {
            return new \WP_REST_Response(['error' => 'audio_file_unavailable'], 404);
        }

        $mime = (string) get_post_mime_type($attachment_id);
        if (! str_starts_with($mime, 'audio/')) {
            return new \WP_REST_Response(['error' => 'not_audio'], 400);
        }

        nocache_headers();
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . (string) filesize($file));
        header('Content-Disposition: attachment; filename="' . sanitize_file_name(basename($file)) . '"');
        header('X-Content-Type-Options: nosniff');
        readfile($file);
        exit;
    }
}
