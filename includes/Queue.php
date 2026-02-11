<?php

if (! defined('ABSPATH')) {
    exit;
}

final class WPAB_Queue
{
    public function __construct(private WPAB_Settings $settings, private WPAB_Logger $logger)
    {
        add_action('add_attachment', [$this, 'maybe_auto_transcribe']);
    }

    public function register_handlers(WPAB_TranscriptionService $transcription, WPAB_ExcerptService $excerpt): void
    {
        add_action('wpab_transcribe_attachment', [$transcription, 'handle'], 10, 1);
        add_action('wpab_transcribe_chunk', [$transcription, 'handle_chunk'], 10, 2);
        add_action('wpab_finalize_transcription', [$transcription, 'finalize_chunked_transcript'], 10, 1);
        add_action('wpab_generate_excerpt', [$excerpt, 'handle'], 10, 1);
    }

    public function enqueue_transcription(int $attachment_id): void
    {
        update_post_meta($attachment_id, WPAB_Meta::TRANSCRIPT_STATUS, 'queued');
        update_post_meta($attachment_id, WPAB_Meta::TRANSCRIPT_ERROR, '');
        $this->enqueue('wpab_transcribe_attachment', [$attachment_id]);
        $this->logger->info('enqueue_transcription', 'Queued transcription job.', $attachment_id);
    }

    public function enqueue_transcription_chunk(int $attachment_id, int $chunk_index): void
    {
        $this->enqueue('wpab_transcribe_chunk', [$attachment_id, $chunk_index]);
    }

    public function enqueue_transcription_finalizer(int $attachment_id): void
    {
        $this->enqueue('wpab_finalize_transcription', [$attachment_id]);
    }

    public function enqueue_excerpt(int $attachment_id): void
    {
        if (! WPAB_Meta::has_transcript($attachment_id) || 'done' === WPAB_Meta::excerpt_status($attachment_id)) {
            $this->logger->info('enqueue_excerpt', 'Skipped excerpt queue.', $attachment_id, ['has_transcript' => WPAB_Meta::has_transcript($attachment_id), 'status' => WPAB_Meta::excerpt_status($attachment_id)]);
            return;
        }

        update_post_meta($attachment_id, WPAB_Meta::EXCERPT_STATUS, 'queued');
        $this->enqueue('wpab_generate_excerpt', [$attachment_id]);
        $this->logger->info('enqueue_excerpt', 'Queued excerpt job.', $attachment_id);
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

        $this->enqueue_transcription($attachment_id);
    }
}
