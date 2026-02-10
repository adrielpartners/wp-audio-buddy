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

    public const EXCERPT = 'wpab_excerpt';
    public const EXCERPT_STATUS = 'wpab_excerpt_status';
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
}
