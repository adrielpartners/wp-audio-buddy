<?php

declare(strict_types=1);

namespace AdrielPartners\WpAudioBuddy\Integrations;

use AdrielPartners\WpAudioBuddy\Controllers\SettingsController;
use AdrielPartners\WpAudioBuddy\Data\LoggerRepository;
use AdrielPartners\WpAudioBuddy\Security\SignatureService;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Client for dispatching transcription jobs to the wpab-worker service.
 *
 * Builds a signed payload with job metadata, HMAC signature, and sends
 * it to the configured worker endpoint.
 */
final class WorkerClient
{
    public function __construct(
        private SettingsController $settings,
        private LoggerRepository $logger
    ) {
    }

    /**
     * Dispatch a transcription job to the worker.
     *
     * @param int    $attachment_id WordPress attachment ID.
     * @param int    $job_id        Local job record ID.
     * @param string $job_uuid      Local job UUID for worker reference.
     *
     * @return bool|\WP_Error True on successful dispatch, WP_Error on failure.
     */
    public function dispatch(int $attachment_id, int $job_id, string $job_uuid): bool|\WP_Error
    {
        $worker_url = trailingslashit((string) $this->settings->get('worker_url', ''));
        $secret = (string) $this->settings->get('worker_shared_secret', '');
        $site_id = (string) $this->settings->get('worker_site_id', '');

        if ('' === trim($worker_url) || '' === trim($secret)) {
            return new \WP_Error('WORKER_NOT_CONFIGURED', 'Worker mode is enabled but worker URL or shared secret is missing.');
        }

        $audio_url = wp_get_attachment_url($attachment_id);
        if (! $audio_url) {
            return new \WP_Error('AUDIO_NOT_FOUND', 'Attachment URL is unavailable for worker dispatch.');
        }

        $timestamp = time();
        $payload = [
            'job_id' => $job_id,
            'job_uuid' => $job_uuid,
            'attachment_id' => $attachment_id,
            'operation' => 'transcribe',
            'audio_url' => $audio_url,
            'callback_url' => rest_url('wp-audio-buddy/v1/transcription-callback'),
            'model' => (string) $this->settings->get('transcription_model', 'gpt-4o-mini-transcribe'),
            'chunk_seconds' => max(60, absint($this->settings->get('worker_chunk_seconds', 660))),
            'timestamp' => $timestamp,
        ];

        if ('' !== $site_id) {
            $payload['site_id'] = $site_id;
        }

        $raw = wp_json_encode($payload);
        if (false === $raw || '' === $raw) {
            return new \WP_Error('WORKER_PAYLOAD_ERROR', 'Failed to encode worker dispatch payload.');
        }

        $signature = SignatureService::sign($raw, $secret);

        $response = wp_remote_post($worker_url . 'v1/jobs/transcribe', [
            'timeout' => 45,
            'headers' => [
                'Content-Type' => 'application/json',
                'X-WPAB-Signature' => $signature,
                'X-WPAB-Timestamp' => (string) $timestamp,
                'X-WPAB-Site-ID' => $site_id ?: get_site_url(),
            ],
            'body' => $raw,
        ]);

        if (is_wp_error($response)) {
            $msg = $response->get_error_message();
            $this->logger->error('worker_dispatch', 'Worker request failed: ' . $msg, $attachment_id);
            return new \WP_Error('WORKER_REQUEST_FAILED', 'Worker request failed: ' . $msg);
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code >= 400) {
            $body = json_decode((string) wp_remote_retrieve_body($response), true);
            $err = (string) ($body['error'] ?? 'Worker rejected the request.');
            $this->logger->error('worker_dispatch', 'Worker rejected request: HTTP ' . $code . ' - ' . $err, $attachment_id);
            return new \WP_Error('WORKER_REJECTED', 'Worker rejected request: ' . $err);
        }

        $this->logger->info('worker_dispatch', 'Worker transcription request accepted.', $attachment_id, [
            'job_id' => $job_id,
            'worker_url' => $worker_url,
        ]);

        return true;
    }
}