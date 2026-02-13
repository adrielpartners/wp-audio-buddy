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
        private WPAB_Logger $logger,
        private WPAB_AudioChunker $chunker
    ) {
    }

    public function dispatch_to_worker(int $attachment_id): void
    {
        $worker_url = trailingslashit((string) $this->settings->get('worker_url', '')) . 'v1/transcribe';
        $secret = (string) $this->settings->get('worker_shared_secret', '');

        if ('' === trim($worker_url) || '' === trim($secret)) {
            $this->fail($attachment_id, 'Worker mode is enabled but worker URL/shared secret are missing.');
            return;
        }

        $audio_url = wp_get_attachment_url($attachment_id);
        if (! $audio_url) {
            $this->fail($attachment_id, 'Attachment URL is unavailable for worker dispatch.');
            return;
        }

        update_post_meta($attachment_id, WPAB_Meta::TRANSCRIPT_STATUS, 'running');

        $payload = [
            'attachment_id' => $attachment_id,
            'audio_url' => $audio_url,
            'callback_url' => rest_url('wp-audio-buddy/v1/transcription-callback'),
            'model' => (string) $this->settings->get('transcription_model', 'gpt-4o-mini-transcribe'),
            'chunk_seconds' => max(60, absint($this->settings->get('worker_chunk_seconds', 660))),
        ];

        $raw = wp_json_encode($payload);
        $signature = self::sign_payload($raw ?: '', $secret);

        $response = wp_remote_post($worker_url, [
            'timeout' => 45,
            'headers' => [
                'Content-Type' => 'application/json',
                'X-WPAB-Signature' => $signature,
            ],
            'body' => $raw,
        ]);

        if (is_wp_error($response)) {
            $this->fail($attachment_id, 'Worker request failed: ' . $response->get_error_message());
            return;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code >= 400) {
            $this->fail($attachment_id, 'Worker rejected request: HTTP ' . $code);
            return;
        }

        $this->logger->info('worker_dispatch', 'Worker transcription request accepted.', $attachment_id, ['worker_url' => $worker_url]);
    }

    public function handle(int $attachment_id): void
    {
        if (! WPAB_Meta::is_audio_attachment($attachment_id)) {
            return;
        }

        WPAB_Meta::clear_chunk_meta($attachment_id);
        update_post_meta($attachment_id, WPAB_Meta::TRANSCRIPT_STATUS, 'running');
        delete_post_meta($attachment_id, WPAB_Meta::TRANSCRIPT_ERROR);

        $api_key = (string) $this->settings->get('api_key', '');
        $model = (string) $this->settings->get('transcription_model', 'gpt-4o-mini-transcribe');
        $file_path = get_attached_file($attachment_id);

        if (empty($api_key) || ! $file_path || ! file_exists($file_path)) {
            $this->fail($attachment_id, 'Missing API key or audio file.');
            return;
        }

        $plan = $this->chunker->prepare($file_path, $attachment_id);
        if (is_wp_error($plan)) {
            $this->fail($attachment_id, $plan->get_error_message());
            $this->logger->error('transcription_chunk_prepare', $plan->get_error_message(), $attachment_id);
            return;
        }

        if (empty($plan['chunking'])) {
            $response = $this->request_transcription($api_key, $model, $file_path, (string) get_post_mime_type($attachment_id));
            if (is_wp_error($response)) {
                $this->fail($attachment_id, $response->get_error_message());
                return;
            }

            $this->save_final_transcript($attachment_id, trim((string) $response['text']), $model);
            return;
        }

        $manifest = (array) ($plan['chunks'] ?? []);
        update_post_meta($attachment_id, WPAB_Meta::TRANSCRIPT_CHUNKING, 1);
        update_post_meta($attachment_id, WPAB_Meta::TRANSCRIPT_CHUNKS_MANIFEST, $manifest);
        update_post_meta($attachment_id, WPAB_Meta::TRANSCRIPT_CHUNKS_TOTAL, (int) $plan['total']);
        update_post_meta($attachment_id, WPAB_Meta::TRANSCRIPT_CHUNKS_DONE, 0);

        foreach ($manifest as $chunk) {
            $index = (int) ($chunk['index'] ?? 0);
            update_post_meta($attachment_id, WPAB_Meta::chunk_status_key($index), 'queued');
            $this->queue->enqueue_transcription_chunk($attachment_id, $index);
        }

        $this->queue->enqueue_transcription_finalizer($attachment_id);
        $this->logger->info('transcription_chunk_prepare', 'Chunk plan prepared and jobs enqueued.', $attachment_id, ['total' => (int) $plan['total']]);
    }

    public function handle_chunk(int $attachment_id, int $chunk_index): void
    {
        if ('error' === WPAB_Meta::transcript_status($attachment_id)) {
            return;
        }

        $manifest = (array) get_post_meta($attachment_id, WPAB_Meta::TRANSCRIPT_CHUNKS_MANIFEST, true);
        $chunk = $this->find_chunk($manifest, $chunk_index);
        if (null === $chunk) {
            $this->fail($attachment_id, 'Missing chunk manifest entry for chunk #' . $chunk_index);
            return;
        }

        $chunk_path = (string) ($chunk['path'] ?? '');
        if (! $chunk_path || ! file_exists($chunk_path)) {
            $this->fail_chunk($attachment_id, $chunk_index, 'Chunk file missing for chunk #' . $chunk_index);
            return;
        }

        update_post_meta($attachment_id, WPAB_Meta::chunk_status_key($chunk_index), 'running');

        $api_key = (string) $this->settings->get('api_key', '');
        $model = (string) $this->settings->get('transcription_model', 'gpt-4o-mini-transcribe');
        $response = $this->request_transcription($api_key, $model, $chunk_path, 'audio/mpeg');

        if (is_wp_error($response)) {
            $this->fail_chunk($attachment_id, $chunk_index, $response->get_error_message());
            return;
        }

        update_post_meta($attachment_id, WPAB_Meta::chunk_text_key($chunk_index), trim((string) $response['text']));
        update_post_meta($attachment_id, WPAB_Meta::chunk_status_key($chunk_index), 'done');

        $done = $this->count_done_chunks($attachment_id, $manifest);
        update_post_meta($attachment_id, WPAB_Meta::TRANSCRIPT_CHUNKS_DONE, $done);

        $this->logger->info('transcription_chunk_done', 'Chunk transcribed.', $attachment_id, ['chunk' => $chunk_index, 'done' => $done]);
        $this->queue->enqueue_transcription_finalizer($attachment_id);
    }

    public function finalize_chunked_transcript(int $attachment_id): void
    {
        if ('error' === WPAB_Meta::transcript_status($attachment_id)) {
            return;
        }

        $manifest = (array) get_post_meta($attachment_id, WPAB_Meta::TRANSCRIPT_CHUNKS_MANIFEST, true);
        $total = (int) get_post_meta($attachment_id, WPAB_Meta::TRANSCRIPT_CHUNKS_TOTAL, true);
        if (empty($manifest) || $total <= 0) {
            return;
        }

        $done = $this->count_done_chunks($attachment_id, $manifest);
        update_post_meta($attachment_id, WPAB_Meta::TRANSCRIPT_CHUNKS_DONE, $done);

        foreach ($manifest as $chunk) {
            $idx = (int) ($chunk['index'] ?? 0);
            if ('error' === (string) get_post_meta($attachment_id, WPAB_Meta::chunk_status_key($idx), true)) {
                $error = (string) get_post_meta($attachment_id, WPAB_Meta::chunk_error_key($idx), true);
                $this->fail($attachment_id, 'Chunk #' . $idx . ' failed: ' . $error);
                return;
            }
        }

        if ($done < $total) {
            $this->logger->info('transcription_finalize_wait', 'Not all chunks done yet.', $attachment_id, ['done' => $done, 'total' => $total]);
            $this->queue->enqueue_transcription_finalizer($attachment_id);
            return;
        }

        usort($manifest, static fn (array $a, array $b): int => ((int) $a['index']) <=> ((int) $b['index']));
        $parts = [];
        foreach ($manifest as $chunk) {
            $parts[] = trim((string) get_post_meta($attachment_id, WPAB_Meta::chunk_text_key((int) $chunk['index']), true));
        }

        $combined = trim(implode("\n\n", array_filter($parts, static fn ($v): bool => '' !== $v)));
        if ('' === $combined) {
            $this->fail($attachment_id, 'Chunk transcription completed but combined transcript was empty.');
            return;
        }

        $model = (string) $this->settings->get('transcription_model', 'gpt-4o-mini-transcribe');
        $this->save_final_transcript($attachment_id, $combined, $model);
        $this->chunker->cleanup($manifest);
        WPAB_Meta::clear_chunk_meta($attachment_id);
        $this->logger->info('transcription_stitch_complete', 'Chunk transcripts stitched and finalized.', $attachment_id, ['total' => $total]);
    }

    public function save_final_transcript(int $attachment_id, string $transcript, string $model, ?int $seconds = null): void
    {
        if ($this->settings->get('auto_format_transcript')) {
            $transcript = $this->excerpt_service->format_transcript($transcript);
        }

        update_post_meta($attachment_id, WPAB_Meta::TRANSCRIPT, $transcript);
        update_post_meta($attachment_id, WPAB_Meta::TRANSCRIPT_STATUS, 'done');
        update_post_meta($attachment_id, WPAB_Meta::TRANSCRIPT_MODEL, $model);
        update_post_meta($attachment_id, WPAB_Meta::TRANSCRIPT_UPDATED, current_time('mysql'));

        if (null === $seconds) {
            $meta = wp_get_attachment_metadata($attachment_id);
            $seconds = is_array($meta) && isset($meta['length']) ? (int) $meta['length'] : 0;
        }
        update_post_meta($attachment_id, WPAB_Meta::TRANSCRIPT_SECONDS, max(0, (int) $seconds));

        $this->logger->info('transcription', 'Transcription generated successfully.', $attachment_id, ['model' => $model, 'seconds' => $seconds]);

        if ($this->settings->get('auto_generate_excerpt')) {
            $this->queue->enqueue_excerpt($attachment_id);
        }
    }

    public static function sign_payload(string $raw_body, string $secret): string
    {
        return hash_hmac('sha256', $raw_body, $secret);
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
            CURLOPT_TIMEOUT => 300,
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

    private function fail_chunk(int $attachment_id, int $chunk_index, string $message): void
    {
        update_post_meta($attachment_id, WPAB_Meta::chunk_status_key($chunk_index), 'error');
        update_post_meta($attachment_id, WPAB_Meta::chunk_error_key($chunk_index), $message);
        $this->fail($attachment_id, 'Chunk #' . $chunk_index . ': ' . $message);
        $this->logger->error('transcription_chunk_error', $message, $attachment_id, ['chunk' => $chunk_index]);
    }

    public function fail(int $attachment_id, string $message): void
    {
        update_post_meta($attachment_id, WPAB_Meta::TRANSCRIPT_STATUS, 'error');
        update_post_meta($attachment_id, WPAB_Meta::TRANSCRIPT_ERROR, $message);
        $this->logger->error('transcription', $message, $attachment_id);
    }

    private function find_chunk(array $manifest, int $chunk_index): ?array
    {
        foreach ($manifest as $chunk) {
            if ((int) ($chunk['index'] ?? -1) === $chunk_index) {
                return $chunk;
            }
        }

        return null;
    }

    private function count_done_chunks(int $attachment_id, array $manifest): int
    {
        $done = 0;
        foreach ($manifest as $chunk) {
            $idx = (int) ($chunk['index'] ?? 0);
            if ('done' === (string) get_post_meta($attachment_id, WPAB_Meta::chunk_status_key($idx), true)) {
                $done++;
            }
        }

        return $done;
    }
}
