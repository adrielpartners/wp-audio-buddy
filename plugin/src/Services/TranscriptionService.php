<?php

declare(strict_types=1);

namespace AdrielPartners\WpAudioBuddy\Services;

use AdrielPartners\WpAudioBuddy\Controllers\SettingsController;
use AdrielPartners\WpAudioBuddy\Data\JobRepository;
use AdrielPartners\WpAudioBuddy\Data\LoggerRepository;
use AdrielPartners\WpAudioBuddy\Data\Meta;
use AdrielPartners\WpAudioBuddy\Data\TranscriptRepository;
use AdrielPartners\WpAudioBuddy\Integrations\Providers\OpenAIProvider;
use AdrielPartners\WpAudioBuddy\Integrations\ProviderRegistry;
use AdrielPartners\WpAudioBuddy\Integrations\WorkerClient;
use AdrielPartners\WpAudioBuddy\Support\AudioChunker;

if (! defined('ABSPATH')) {
    exit;
}

final class TranscriptionService
{
    public function __construct(
        private SettingsController $settings,
        private QueueService $queue,
        private ExcerptService $excerpt_service,
        private LoggerRepository $logger,
        private AudioChunker $chunker,
        private JobRepository $jobs,
        private TranscriptRepository $transcripts,
        private WorkerClient $worker
    ) {
    }

    public function dispatch_to_worker(int $attachment_id, ?int $job_id = null): void
    {
        $job = $this->current_job($attachment_id, $job_id);
        if (null === $job) {
            $this->fail($attachment_id, 'Cannot dispatch to worker: no local job record found.', $job_id);
            return;
        }

        $job_id = (int) $job['id'];
        $job_uuid = (string) ($job['job_uuid'] ?? '');

        update_post_meta($attachment_id, Meta::TRANSCRIPT_STATUS, 'running');
        $this->update_job_status($attachment_id, $job_id, 'waiting_for_worker', [
            'started_at' => current_time('mysql'),
            'attempt_count' => ((int) ($job['attempt_count'] ?? 0)) + 1,
        ]);

        $result = $this->worker->dispatch($attachment_id, $job_id, $job_uuid);

        if (is_wp_error($result)) {
            $this->fail($attachment_id, $result->get_error_message(), $job_id);
            return;
        }

        $this->logger->info('worker_dispatch', 'Worker transcription request accepted.', $attachment_id, [
            'job_id' => $job_id,
            'job_uuid' => $job_uuid,
        ]);
    }

    public function handle(int $attachment_id, ?int $job_id = null): void
    {
        try {
            // Increase time limit for long-running transcription jobs.
            if (function_exists('set_time_limit')) {
                set_time_limit(600);
            }
            if (function_exists('as_set_time_limit')) {
                as_set_time_limit(600);
            }

            if (! Meta::is_audio_attachment($attachment_id)) {
                return;
            }

            Meta::clear_chunk_meta($attachment_id);
            update_post_meta($attachment_id, Meta::TRANSCRIPT_STATUS, 'running');
            delete_post_meta($attachment_id, Meta::TRANSCRIPT_ERROR);
            $job = $this->current_job($attachment_id, $job_id);
            $job_id = $job ? (int) $job['id'] : $job_id;
            $this->update_job_status($attachment_id, $job_id, 'running', ['started_at' => current_time('mysql')]);

        $config = $this->settings->getProviderConfig('transcription');
        $file_path = get_attached_file($attachment_id);

        if (empty($config['api_key']) || ! $file_path || ! file_exists($file_path)) {
            $this->fail($attachment_id, 'Missing API key or audio file.', $job_id);
            return;
        }

        $plan = 'openrouter' === ($config['provider'] ?? '')
            ? $this->chunker->prepare($file_path, $attachment_id, 55, 55, 10485760)
            : $this->chunker->prepare($file_path, $attachment_id);
        if (is_wp_error($plan)) {
            $this->fail($attachment_id, $plan->get_error_message(), $job_id);
            $this->logger->error('transcription_chunk_prepare', $plan->get_error_message(), $attachment_id);
            return;
        }

        if (empty($plan['chunking'])) {
            $provider = ProviderRegistry::getTranscriptionProvider($config['provider']);
            if ($provider === null) {
                $this->fail($attachment_id, 'No transcription provider available for: ' . ($config['provider'] ?? 'none'), $job_id ?? null);
                return;
            }
            $response = $provider->transcribe($file_path, (string) get_post_mime_type($attachment_id), $config);
            if (is_wp_error($response)) {
                $this->handle_transcription_error($attachment_id, $response, $job_id);
                return;
            }

            $this->save_final_transcript($attachment_id, trim((string) $response['text']), $config['model'], null, $job_id);
            return;
        }

        $manifest = (array) ($plan['chunks'] ?? []);
        update_post_meta($attachment_id, Meta::TRANSCRIPT_CHUNKING, 1);
        update_post_meta($attachment_id, Meta::TRANSCRIPT_CHUNKS_MANIFEST, $manifest);
        update_post_meta($attachment_id, Meta::TRANSCRIPT_CHUNKS_TOTAL, (int) $plan['total']);
        update_post_meta($attachment_id, Meta::TRANSCRIPT_CHUNKS_DONE, 0);

        foreach ($manifest as $chunk) {
            $index = (int) ($chunk['index'] ?? 0);
            update_post_meta($attachment_id, Meta::chunk_status_key($index), 'queued');
            $this->queue->enqueue_transcription_chunk($attachment_id, $index, $job_id);
        }

        $this->queue->enqueue_transcription_finalizer($attachment_id, $job_id);
        $this->logger->info('transcription_chunk_prepare', 'Chunk plan prepared and jobs enqueued.', $attachment_id, ['total' => (int) $plan['total']]);
        } catch (\Throwable $e) {
            $message = 'Transcription error: ' . $e->getMessage();
            $this->logger->error('transcription_exception', $message, $attachment_id, ['file' => $e->getFile(), 'line' => $e->getLine()]);
            $this->fail($attachment_id, $message, $job_id);
        }
    }

    public function handle_chunk(int $attachment_id, int $chunk_index, ?int $job_id = null): void
    {
        if ('error' === Meta::transcript_status($attachment_id)) {
            return;
        }

        $manifest = (array) get_post_meta($attachment_id, Meta::TRANSCRIPT_CHUNKS_MANIFEST, true);
        $chunk = $this->find_chunk($manifest, $chunk_index);
        if (null === $chunk) {
            $this->fail($attachment_id, 'Missing chunk manifest entry for chunk #' . $chunk_index, $job_id);
            return;
        }

        $chunk_path = (string) ($chunk['path'] ?? '');
        if (! $chunk_path || ! file_exists($chunk_path)) {
            $this->fail_chunk($attachment_id, $chunk_index, 'Chunk file missing for chunk #' . $chunk_index, $job_id);
            return;
        }

        update_post_meta($attachment_id, Meta::chunk_status_key($chunk_index), 'running');

        $config = $this->settings->getProviderConfig('transcription');
        $provider = ProviderRegistry::getTranscriptionProvider($config['provider']);
        if ($provider === null) {
            $this->fail_chunk($attachment_id, $chunk_index, 'No transcription provider available.', $job_id);
            return;
        }
        $response = $provider->transcribe($chunk_path, 'audio/mpeg', $config);

        if (is_wp_error($response)) {
            $this->fail_chunk($attachment_id, $chunk_index, $response->get_error_message(), $job_id);
            return;
        }

        update_post_meta($attachment_id, Meta::chunk_text_key($chunk_index), trim((string) $response['text']));
        update_post_meta($attachment_id, Meta::chunk_status_key($chunk_index), 'done');

        $done = $this->count_done_chunks($attachment_id, $manifest);
        update_post_meta($attachment_id, Meta::TRANSCRIPT_CHUNKS_DONE, $done);

        $this->logger->info('transcription_chunk_done', 'Chunk transcribed.', $attachment_id, ['chunk' => $chunk_index, 'done' => $done]);
        $this->queue->enqueue_transcription_finalizer($attachment_id, $job_id);
    }

    public function finalize_chunked_transcript(int $attachment_id, ?int $job_id = null): void
    {
        if ('error' === Meta::transcript_status($attachment_id)) {
            return;
        }

        $manifest = (array) get_post_meta($attachment_id, Meta::TRANSCRIPT_CHUNKS_MANIFEST, true);
        $total = (int) get_post_meta($attachment_id, Meta::TRANSCRIPT_CHUNKS_TOTAL, true);
        if (empty($manifest) || $total <= 0) {
            return;
        }

        $done = $this->count_done_chunks($attachment_id, $manifest);
        update_post_meta($attachment_id, Meta::TRANSCRIPT_CHUNKS_DONE, $done);

        foreach ($manifest as $chunk) {
            $idx = (int) ($chunk['index'] ?? 0);
            if ('error' === (string) get_post_meta($attachment_id, Meta::chunk_status_key($idx), true)) {
                $error = (string) get_post_meta($attachment_id, Meta::chunk_error_key($idx), true);
                $this->fail($attachment_id, 'Chunk #' . $idx . ' failed: ' . $error, $job_id);
                return;
            }
        }

        if ($done < $total) {
            $this->logger->info('transcription_finalize_wait', 'Not all chunks done yet.', $attachment_id, ['done' => $done, 'total' => $total]);
            $this->queue->enqueue_transcription_finalizer($attachment_id, $job_id);
            return;
        }

        usort($manifest, static fn (array $a, array $b): int => ((int) $a['index']) <=> ((int) $b['index']));
        $parts = [];
        foreach ($manifest as $chunk) {
            $parts[] = trim((string) get_post_meta($attachment_id, Meta::chunk_text_key((int) $chunk['index']), true));
        }

        $combined = trim(implode("\n\n", array_filter($parts, static fn ($v): bool => '' !== $v)));
        if ('' === $combined) {
            $this->fail($attachment_id, 'Chunk transcription completed but combined transcript was empty.', $job_id);
            return;
        }

        $config = $this->settings->getProviderConfig('transcription');
        $model = $config['model'];
        $this->save_final_transcript($attachment_id, $combined, $model, null, $job_id);
        $this->chunker->cleanup($manifest);
        Meta::clear_chunk_meta($attachment_id);
        $this->logger->info('transcription_stitch_complete', 'Chunk transcripts stitched and finalized.', $attachment_id, ['total' => $total]);
    }

    public function save_final_transcript(int $attachment_id, string $transcript, string $model, ?int $seconds = null, ?int $job_id = null): void
    {
        if ($this->settings->get('auto_format_transcript')) {
            $transcript = $this->excerpt_service->format_transcript($transcript);
        }

        update_post_meta($attachment_id, Meta::TRANSCRIPT, $transcript);
        update_post_meta($attachment_id, Meta::TRANSCRIPT_STATUS, 'done');
        update_post_meta($attachment_id, Meta::TRANSCRIPT_MODEL, $model);
        update_post_meta($attachment_id, Meta::TRANSCRIPT_UPDATED, current_time('mysql'));

        if (null === $seconds) {
            $meta = wp_get_attachment_metadata($attachment_id);
            $seconds = is_array($meta) && isset($meta['length']) ? (int) $meta['length'] : 0;
        }
        update_post_meta($attachment_id, Meta::TRANSCRIPT_SECONDS, max(0, (int) $seconds));

        $job = $this->current_job($attachment_id, $job_id);
        $job_id = $job ? (int) $job['id'] : $job_id;
        $this->update_job_status($attachment_id, $job_id, 'completed', ['completed_at' => current_time('mysql')]);

        $this->transcripts->insert([
            'attachment_id' => $attachment_id,
            'job_id' => $job_id,
            'transcript_text' => $transcript,
            'metadata_json' => wp_json_encode([
                'model' => $model,
                'seconds' => $seconds,
            ]),
        ]);

        $this->logger->info('transcription', 'Transcription generated successfully.', $attachment_id, ['model' => $model, 'seconds' => $seconds]);

        if ($this->settings->get('auto_generate_excerpt')) {
            $this->queue->enqueue_excerpt($attachment_id, 'auto');
        }

        if ($this->settings->get('auto_generate_excerpt')) {
            $this->queue->enqueue_topics($attachment_id);
        }
    }

    /**
     * Find the most recent job record for an attachment.
     */
    private function current_job(int $attachment_id, ?int $job_id = null): ?array
    {
        if (null !== $job_id && $job_id > 0) {
            $job = $this->jobs->get_by_id($job_id);
            if (null !== $job && (int) ($job['attachment_id'] ?? 0) === $attachment_id && 'transcribe' === ($job['operation'] ?? '')) {
                return $job;
            }
        }

        return $this->jobs->get_latest_for_attachment_operation($attachment_id, 'transcribe');
    }

    /**
     * Update the status on the latest job for an attachment, if one exists.
     */
    private function update_job_status(int $attachment_id, ?int $job_id, string $status, array $extra = []): void
    {
        $job = $this->current_job($attachment_id, $job_id);
        if (null === $job) {
            return;
        }

        $data = array_merge(['status' => $status], $extra);
        $this->jobs->update((int) $job['id'], $data);
    }

    /**
     * Handle a transcription API error with bounded retry for transient failures.
     */
    private function handle_transcription_error(int $attachment_id, \WP_Error $error, ?int $job_id = null): void
    {
        $message = $error->get_error_message();

        if (OpenAIProvider::is_transient_error($error)) {
            $job = $this->current_job($attachment_id, $job_id);
            $attempts = $job ? (int) ($job['attempt_count'] ?? 0) : 0;

            if ($attempts < OpenAIProvider::MAX_RETRIES) {
                $new_attempts = $attempts + 1;
                $job_id = $job ? (int) $job['id'] : $job_id;
                $processing_mode = (string) ($job['processing_mode'] ?? 'local');
                $this->update_job_status($attachment_id, $job_id, 'queued', [
                    'attempt_count' => $new_attempts,
                ]);
                if (null !== $job_id && $job_id > 0) {
                    $this->queue->enqueue_existing_transcription($attachment_id, $job_id, $processing_mode);
                }
                $this->logger->info('transcription_retry', 'Retrying transcription after transient error.', $attachment_id, [
                    'attempt' => $new_attempts,
                    'max' => OpenAIProvider::MAX_RETRIES,
                    'error' => $message,
                ]);
                return;
            }

            $message = 'Transcription failed after ' . OpenAIProvider::MAX_RETRIES . ' attempts: ' . $message;
        }

        $this->fail($attachment_id, $message, $job_id);
    }

    private function fail_chunk(int $attachment_id, int $chunk_index, string $message, ?int $job_id = null): void
    {
        update_post_meta($attachment_id, Meta::chunk_status_key($chunk_index), 'error');
        update_post_meta($attachment_id, Meta::chunk_error_key($chunk_index), $message);
        $this->fail($attachment_id, 'Chunk #' . $chunk_index . ': ' . $message, $job_id);
        $this->logger->error('transcription_chunk_error', $message, $attachment_id, ['chunk' => $chunk_index]);
    }

    public function fail(int $attachment_id, string $message, ?int $job_id = null): void
    {
        update_post_meta($attachment_id, Meta::TRANSCRIPT_STATUS, 'error');
        update_post_meta($attachment_id, Meta::TRANSCRIPT_ERROR, $message);
        $this->update_job_status($attachment_id, $job_id, 'failed', [
            'failed_at' => current_time('mysql'),
            'error_message' => $message,
            'error_code' => 'transcription_failed',
        ]);
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
            if ('done' === (string) get_post_meta($attachment_id, Meta::chunk_status_key($idx), true)) {
                $done++;
            }
        }

        return $done;
    }
}
