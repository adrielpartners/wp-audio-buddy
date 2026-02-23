<?php

if (! defined('ABSPATH')) {
    exit;
}

final class WPAB_Meta
{
    public const TRANSCRIPT = 'wpab_transcript';
    public const TRANSCRIPT_STATUS = 'wpab_transcript_status';
    public const TRANSCRIPT_ERROR = 'wpab_transcript_error';
    public const TRANSCRIPT_MODEL = 'wpab_transcript_model';
    public const TRANSCRIPT_SECONDS = 'wpab_transcript_seconds';
    public const TRANSCRIPT_UPDATED = 'wpab_transcript_updated';

    public const TRANSCRIPT_CHUNKING = 'wpab_transcript_chunking';
    public const TRANSCRIPT_CHUNKS_TOTAL = 'wpab_transcript_chunks_total';
    public const TRANSCRIPT_CHUNKS_DONE = 'wpab_transcript_chunks_done';
    public const TRANSCRIPT_CHUNKS_MANIFEST = 'wpab_transcript_chunks_manifest';

    public const EXCERPT = 'wpab_excerpt';
    public const EXCERPT_STATUS = 'wpab_excerpt_status';
    public const EXCERPT_ERROR = 'wpab_excerpt_error';
    public const EXCERPT_MODEL = 'wpab_excerpt_model';
    public const EXCERPT_PROMPT_TYPE = 'wpab_excerpt_prompt_type';
    public const EXCERPT_PROMPT_CUSTOM = 'wpab_excerpt_prompt_custom';
    public const EXCERPT_UPDATED = 'wpab_excerpt_updated';

    public static function is_audio_attachment(int $attachment_id): bool
    {
        return str_starts_with((string) get_post_mime_type($attachment_id), 'audio/');
    }

    public static function transcript_status(int $attachment_id): string
    {
        return (string) get_post_meta($attachment_id, self::TRANSCRIPT_STATUS, true) ?: 'none';
    }

    public static function excerpt_status(int $attachment_id): string
    {
        return (string) get_post_meta($attachment_id, self::EXCERPT_STATUS, true) ?: 'none';
    }

    public static function has_transcript(int $attachment_id): bool
    {
        return '' !== trim((string) get_post_meta($attachment_id, self::TRANSCRIPT, true));
    }

    public static function chunk_text_key(int $index): string
    {
        return 'wpab_transcript_chunk_' . $index . '_text';
    }

    public static function chunk_status_key(int $index): string
    {
        return 'wpab_transcript_chunk_' . $index . '_status';
    }

    public static function chunk_error_key(int $index): string
    {
        return 'wpab_transcript_chunk_' . $index . '_error';
    }

    public static function clear_chunk_meta(int $attachment_id): void
    {
        delete_post_meta($attachment_id, self::TRANSCRIPT_CHUNKING);
        delete_post_meta($attachment_id, self::TRANSCRIPT_CHUNKS_TOTAL);
        delete_post_meta($attachment_id, self::TRANSCRIPT_CHUNKS_DONE);
        delete_post_meta($attachment_id, self::TRANSCRIPT_CHUNKS_MANIFEST);

        $all = get_post_meta($attachment_id);
        foreach (array_keys($all) as $key) {
            if (str_starts_with((string) $key, 'wpab_transcript_chunk_')) {
                delete_post_meta($attachment_id, (string) $key);
            }
        }
    }

    public static function chunk_progress_label(int $attachment_id): string
    {
        $chunking = (bool) get_post_meta($attachment_id, self::TRANSCRIPT_CHUNKING, true);
        if (! $chunking) {
            return '';
        }

        $done = (int) get_post_meta($attachment_id, self::TRANSCRIPT_CHUNKS_DONE, true);
        $total = (int) get_post_meta($attachment_id, self::TRANSCRIPT_CHUNKS_TOTAL, true);
        $current = min($total, max($done + 1, 1));

        if ($total <= 0) {
            return '';
        }

        return sprintf('Transcribing chunk %d of %d', $current, $total);
    }
}
