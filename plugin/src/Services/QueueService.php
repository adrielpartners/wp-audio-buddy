<?php

declare(strict_types=1);

namespace AdrielPartners\WpAudioBuddy\Services;

use AdrielPartners\WpAudioBuddy\Controllers\SettingsController;
use AdrielPartners\WpAudioBuddy\Data\JobRepository;
use AdrielPartners\WpAudioBuddy\Data\LoggerRepository;
use AdrielPartners\WpAudioBuddy\Data\Meta;
use AdrielPartners\WpAudioBuddy\Data\GeneratedOutputRepository;
use AdrielPartners\WpAudioBuddy\Data\TranscriptRepository;

if (! defined('ABSPATH')) {
    exit;
}

final class QueueService
{
    public function __construct(
        private SettingsController $settings,
        private LoggerRepository $logger,
        private JobRepository $jobs
    ) {
        add_action('add_attachment', [$this, 'maybe_auto_transcribe']);
    }

    public function register_handlers(TranscriptionService $transcription, ExcerptService $excerpt): void
    {
        add_action('wpab_transcribe_attachment', [$transcription, 'handle'], 10, 2);
        add_action('wpab_dispatch_worker_transcription', [$transcription, 'dispatch_to_worker'], 10, 2);
        add_action('wpab_transcribe_chunk', [$transcription, 'handle_chunk'], 10, 3);
        add_action('wpab_finalize_transcription', [$transcription, 'finalize_chunked_transcript'], 10, 2);
        add_action('wpab_generate_excerpt', [$excerpt, 'handle'], 10, 2);
        add_action('wpab_generate_topics', [$excerpt, 'handle_topics'], 10, 1);
    }

    public function enqueue_transcription(int $attachment_id, string $source = 'manual'): void
    {
        update_post_meta($attachment_id, Meta::TRANSCRIPT_STATUS, 'queued');
        update_post_meta($attachment_id, Meta::TRANSCRIPT_ERROR, '');

        $decision = $this->should_use_worker($attachment_id);
        if (is_wp_error($decision)) {
            update_post_meta($attachment_id, Meta::TRANSCRIPT_STATUS, 'error');
            update_post_meta($attachment_id, Meta::TRANSCRIPT_ERROR, $decision->get_error_message());
            $this->logger->error('enqueue_transcription', $decision->get_error_message(), $attachment_id);
            return;
        }

        $job_id = $this->jobs->insert([
            'attachment_id' => $attachment_id,
            'operation' => 'transcribe',
            'processing_mode' => $decision ? 'worker' : 'local',
            'status' => 'queued',
            'source' => $source,
        ]);

        $this->enqueue_existing_transcription($attachment_id, $job_id, $decision ? 'worker' : 'local');

        $this->logger->info('enqueue_transcription', 'Queued transcription job.', $attachment_id, [
            'worker_mode' => $decision,
            'job_id' => $job_id,
        ]);
    }

    public function enqueue_existing_transcription(int $attachment_id, int $job_id, string $processing_mode): void
    {
        $hook = 'worker' === $processing_mode ? 'wpab_dispatch_worker_transcription' : 'wpab_transcribe_attachment';
        $this->enqueue($hook, [$attachment_id, $job_id]);
    }

    public function enqueue_transcription_chunk(int $attachment_id, int $chunk_index, ?int $job_id = null): void
    {
        $this->enqueue('wpab_transcribe_chunk', [$attachment_id, $chunk_index, $job_id]);
    }

    public function enqueue_transcription_finalizer(int $attachment_id, ?int $job_id = null): void
    {
        $this->enqueue('wpab_finalize_transcription', [$attachment_id, $job_id]);
    }

    public function enqueue_excerpt(int $attachment_id, string $source = 'manual'): void
    {
        $has_transcript = $this->has_transcript($attachment_id);
        $has_excerpt = $this->has_excerpt($attachment_id);
        if (! $has_transcript || $has_excerpt) {
            $this->logger->info('enqueue_excerpt', 'Skipped excerpt queue.', $attachment_id, ['has_transcript' => $has_transcript, 'status' => Meta::excerpt_status($attachment_id)]);
            return;
        }

        update_post_meta($attachment_id, Meta::EXCERPT_STATUS, 'queued');

        $job_id = $this->jobs->insert([
            'attachment_id' => $attachment_id,
            'operation' => 'excerpt',
            'processing_mode' => 'local',
            'status' => 'queued',
            'source' => $source,
        ]);

        $this->enqueue_existing_excerpt($attachment_id, $job_id);

        $this->logger->info('enqueue_excerpt', 'Queued excerpt job.', $attachment_id, ['job_id' => $job_id]);
    }

    public function enqueue_existing_excerpt(int $attachment_id, int $job_id): void
    {
        $this->enqueue('wpab_generate_excerpt', [$attachment_id, $job_id]);
    }

    public function enqueue_topics(int $attachment_id): void
    {
        $transcript = (string) get_post_meta($attachment_id, Meta::TRANSCRIPT, true);
        if ('' === trim($transcript)) {
            return;
        }

        if ('done' === Meta::topics_status($attachment_id)) {
            return;
        }

        update_post_meta($attachment_id, Meta::TOPICS_STATUS, 'queued');
        $this->enqueue('wpab_generate_topics', [$attachment_id]);
    }

    private function should_use_worker(int $attachment_id): bool|\WP_Error
    {
        $mode = $this->settings->get('processing_mode', 'auto');
        $worker_configured = $this->worker_is_configured();

        if ('wordpress_only' === $mode) {
            $file_path = (string) get_attached_file($attachment_id);
            if ('' === $file_path || ! is_readable($file_path)) {
                return new \WP_Error('wpab_audio_file_missing', 'Audio file is missing or unreadable for local transcription.');
            }
            $size = filesize($file_path);
            if (false !== $size && (int) $size > (int) $this->settings->get('worker_file_size_threshold', 20971520) && ! $this->local_chunking_is_available()) {
                return new \WP_Error('wpab_local_file_too_large', 'This audio file is too large for local WordPress processing on this server. Configure the worker or install FFmpeg for local chunking.');
            }
            return false;
        }

        if ('worker_only' === $mode) {
            if (! $worker_configured) {
                return new \WP_Error('wpab_worker_not_configured', 'Worker-only mode is enabled but the worker URL or shared secret is missing.');
            }
            return true;
        }

        // Auto mode
        if (! $worker_configured) {
            $file_path = (string) get_attached_file($attachment_id);
            if ('' === $file_path || ! is_readable($file_path)) {
                return new \WP_Error('wpab_audio_file_missing', 'Audio file is missing or unreadable for direct transcription.');
            }
            $size = filesize($file_path);
            if (false !== $size && (int) $size > (int) $this->settings->get('worker_file_size_threshold', 20971520) && ! $this->local_chunking_is_available()) {
                return new \WP_Error('wpab_worker_recommended', 'This audio file is larger than the local processing threshold and no worker is configured. Configure the worker or use a smaller file.');
            }
            return false;
        }

        $file_path = (string) get_attached_file($attachment_id);
        if ('' === $file_path || ! is_readable($file_path)) {
            $this->logger->info('enqueue_transcription', 'Audio file unavailable locally; using worker fallback.', $attachment_id);
            return true;
        }

        $size = filesize($file_path);
        if (false === $size) {
            $this->logger->info('enqueue_transcription', 'Unable to read audio filesize locally; using worker fallback.', $attachment_id);
            return true;
        }

        $threshold = (int) $this->settings->get('worker_file_size_threshold', 20971520);
        $use_worker = (int) $size > $threshold;
        $this->logger->info('enqueue_transcription', 'Worker routing decision evaluated.', $attachment_id, [
            'filesize' => (int) $size,
            'threshold' => $threshold,
            'use_worker' => $use_worker,
            'mode' => $mode,
        ]);

        return $use_worker;
    }

    private function worker_is_configured(): bool
    {
        return '' !== trim((string) $this->settings->get('worker_url', ''))
            && '' !== trim((string) $this->settings->get('worker_shared_secret', ''));
    }

    private function local_chunking_is_available(): bool
    {
        if (! function_exists('exec')) {
            return false;
        }

        $output = [];
        $return = 1;
        @exec('command -v ffmpeg 2>/dev/null', $output, $return);

        return 0 === $return && ! empty($output);
    }

    private function has_transcript(int $attachment_id): bool
    {
        if (Meta::has_transcript($attachment_id)) {
            return true;
        }

        $transcripts = new TranscriptRepository();
        $row = $transcripts->get_latest_for_attachment($attachment_id);

        return null !== $row && '' !== trim((string) ($row['transcript_text'] ?? ''));
    }

    private function has_excerpt(int $attachment_id): bool
    {
        if ('done' === Meta::excerpt_status($attachment_id) && '' !== trim((string) get_post_meta($attachment_id, Meta::EXCERPT, true))) {
            return true;
        }

        $outputs = new GeneratedOutputRepository();
        $row = $outputs->get_latest_for_attachment($attachment_id, 'excerpt');

        return null !== $row && '' !== trim((string) ($row['output_text'] ?? ''));
    }

    private function enqueue(string $hook, array $args): void
    {
        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action($hook, $args, 'wp-audio-buddy');
            return;
        }

        if (! wp_next_scheduled($hook, $args)) {
            wp_schedule_single_event(time() + 10, $hook, $args);
        }
    }

    public function maybe_auto_transcribe(int $attachment_id): void
    {
        if (! $this->settings->get('auto_transcribe_upload')) {
            return;
        }

        if ('audio/mpeg' !== get_post_mime_type($attachment_id)) {
            return;
        }

        $this->enqueue_transcription($attachment_id, 'auto');
    }
}
