<?php

if (! defined('ABSPATH')) {
    exit;
}

final class WPAB_TranscriptionService
{
    public function __construct(
        private WPAB_Settings $settings,
        private WPAB_Queue $queue,
        private WPAB_ExcerptService $excerpt_service
    ) {
    }

    public function handle(int $attachment_id): void
    {
        if (! WPAB_Meta::is_audio_attachment($attachment_id)) {
            return;
        }

        if ('done' === WPAB_Meta::transcript_status($attachment_id) && WPAB_Meta::has_transcript($attachment_id)) {
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

        $response = wp_remote_post('https://api.openai.com/v1/audio/transcriptions', [
            'timeout' => 180,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
            ],
            'body' => [
                'file' => curl_file_create($file_path, get_post_mime_type($attachment_id), basename($file_path)),
                'model' => $model,
                'response_format' => 'json',
            ],
        ]);

        if (is_wp_error($response)) {
            $this->fail($attachment_id, $response->get_error_message());
            return;
        }

        $status = wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);

        if ($status >= 400 || empty($body['text'])) {
            $message = $body['error']['message'] ?? 'Transcription failed.';
            $this->fail($attachment_id, $message);
            return;
        }

        $transcript = trim((string) $body['text']);
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

        if ($this->settings->get('auto_generate_excerpt')) {
            $this->queue->enqueue_excerpt($attachment_id);
        }
    }

    private function fail(int $attachment_id, string $message): void
    {
        update_post_meta($attachment_id, WPAB_Meta::TRANSCRIPT_STATUS, 'error');
        update_post_meta($attachment_id, WPAB_Meta::TRANSCRIPT_ERROR, $message);
    }
}
