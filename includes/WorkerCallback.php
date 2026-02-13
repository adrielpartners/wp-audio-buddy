<?php

if (! defined('ABSPATH')) {
    exit;
}

final class WPAB_Worker_Callback
{
    public function __construct(
        private WPAB_Settings $settings,
        private WPAB_Logger $logger,
        private WPAB_TranscriptionService $transcription
    ) {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes(): void
    {
        register_rest_route('wp-audio-buddy/v1', '/transcription-callback', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_callback'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function handle_callback(WP_REST_Request $request): WP_REST_Response
    {
        $raw = (string) $request->get_body();
        $header_sig = (string) $request->get_header('x-wpab-signature');
        $secret = (string) $this->settings->get('worker_shared_secret', '');

        if ('' === $secret) {
            return new WP_REST_Response(['error' => 'worker_shared_secret_missing'], 401);
        }

        $expected = WPAB_TranscriptionService::sign_payload($raw, $secret);
        $provided = str_starts_with($header_sig, 'sha256=') ? substr($header_sig, 7) : $header_sig;

        if (! hash_equals($expected, $provided)) {
            $this->logger->error('worker_callback', 'Invalid worker callback signature.');
            return new WP_REST_Response(['error' => 'invalid_signature'], 401);
        }

        $data = json_decode($raw, true);
        if (! is_array($data)) {
            return new WP_REST_Response(['error' => 'invalid_json'], 400);
        }

        $attachment_id = absint($data['attachment_id'] ?? 0);
        if ($attachment_id <= 0 || ! WPAB_Meta::is_audio_attachment($attachment_id)) {
            return new WP_REST_Response(['error' => 'invalid_attachment'], 400);
        }

        $status = sanitize_text_field((string) ($data['status'] ?? 'done'));
        if ('error' === $status) {
            $message = sanitize_text_field((string) ($data['error'] ?? 'Worker transcription failed.'));
            $this->transcription->fail($attachment_id, $message);
            $this->logger->error('worker_callback', $message, $attachment_id);
            return new WP_REST_Response(['ok' => true], 200);
        }

        $transcript = trim((string) ($data['transcript'] ?? ''));
        if ('' === $transcript) {
            $this->transcription->fail($attachment_id, 'Worker callback missing transcript.');
            return new WP_REST_Response(['error' => 'missing_transcript'], 400);
        }

        $model = sanitize_text_field((string) ($data['model'] ?? $this->settings->get('transcription_model', 'gpt-4o-mini-transcribe')));
        $seconds = isset($data['seconds']) ? (int) $data['seconds'] : null;

        $this->transcription->save_final_transcript($attachment_id, $transcript, $model, $seconds);

        $minutes = max(0, (float) ($seconds ?? 0) / 60);
        $existing = (float) get_option('wpab_total_minutes_transcribed', 0);
        update_option('wpab_total_minutes_transcribed', round($existing + $minutes, 4), false);

        $this->logger->info('worker_callback', 'Worker transcript callback processed.', $attachment_id, ['seconds' => $seconds, 'model' => $model]);


        return new WP_REST_Response(['ok' => true], 200);
    }
}
