<?php

if (! defined('ABSPATH')) {
    exit;
}

final class WPAB_TranscriptionService
{
    public function __construct(
        private WPAB_Settings $settings,
        private WPAB_Queue $queue,
        private WPAB_ExcerptService $excerpt_service,
        private WPAB_Logger $logger
    ) {
    }

    public function handle(int $attachment_id): void
    {
        if (! WPAB_Meta::is_audio_attachment($attachment_id)) {
            return;
        }

        if ('done' === WPAB_Meta::transcript_status($attachment_id) && WPAB_Meta::has_transcript($attachment_id)) {
            $this->logger->info('transcription', 'Skipped transcription: already complete.', $attachment_id);
            return;
        }

        update_post_meta($attachment_id, WPAB_Meta::TRANSCRIPT_STATUS, 'running');
        delete_post_meta($attachment_id, WPAB_Meta::TRANSCRIPT_ERROR);

        $api_key = (string) $this->settings->get('api_key', '');
        $model = (string) $this->settings->get('transcription_model', 'gpt-4o-mini-transcribe');
        $file_path = get_attached_file($attachment_id);

        if (empty($api_key) || ! $file_path || ! file_exists($file_path)) {
            $this->fail($attachment_id, 'Missing API key or audio file.');
            return;
        }

        $response = $this->request_transcription($api_key, $model, $file_path, (string) get_post_mime_type($attachment_id));
        if (is_wp_error($response)) {
            $this->fail($attachment_id, $response->get_error_message());
            return;
        }

        $transcript = trim((string) $response['text']);
        if ($this->settings->get('auto_format_transcript')) {
            $transcript = $this->excerpt_service->format_transcript($transcript);
        }

        update_post_meta($attachment_id, WPAB_Meta::TRANSCRIPT, $transcript);
        update_post_meta($attachment_id, WPAB_Meta::TRANSCRIPT_STATUS, 'done');
        update_post_meta($attachment_id, WPAB_Meta::TRANSCRIPT_MODEL, $model);
        update_post_meta($attachment_id, WPAB_Meta::TRANSCRIPT_UPDATED, current_time('mysql'));

        $meta = wp_get_attachment_metadata($attachment_id);
        $length = is_array($meta) && isset($meta['length']) ? (int) $meta['length'] : 0;
        update_post_meta($attachment_id, WPAB_Meta::TRANSCRIPT_SECONDS, $length);

        $this->logger->info('transcription', 'Transcription generated successfully.', $attachment_id, ['model' => $model]);

        if ($this->settings->get('auto_generate_excerpt')) {
            $this->queue->enqueue_excerpt($attachment_id);
        }
    }

    private function request_transcription(string $api_key, string $model, string $file_path, string $mime): array|WP_Error
    {
        if (! function_exists('curl_init')) {
            return new WP_Error('wpab_curl_missing', 'cURL is required for audio transcription requests.');
        }

        $ch = curl_init('https://api.openai.com/v1/audio/transcriptions');
        $postfields = [
            'file' => curl_file_create($file_path, $mime ?: 'application/octet-stream', basename($file_path)),
            'model' => $model,
            'response_format' => 'json',
        ];

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postfields,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $api_key],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 180,
        ]);

        $raw = curl_exec($ch);
        $curl_err = curl_error($ch);
        $http_code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if (false === $raw) {
            return new WP_Error('wpab_transcribe_transport', $curl_err ?: 'Transcription request failed.');
        }

        $body = json_decode((string) $raw, true);
        if ($http_code >= 400) {
            return new WP_Error('wpab_transcribe_api', $body['error']['message'] ?? 'Transcription failed.');
        }

        if (empty($body['text'])) {
            return new WP_Error('wpab_transcribe_empty', 'No transcript text was returned by OpenAI.');
        }

        return $body;
    }

    private function fail(int $attachment_id, string $message): void
    {
        update_post_meta($attachment_id, WPAB_Meta::TRANSCRIPT_STATUS, 'error');
        update_post_meta($attachment_id, WPAB_Meta::TRANSCRIPT_ERROR, $message);
        $this->logger->error('transcription', $message, $attachment_id);
    }
}
