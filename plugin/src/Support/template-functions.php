<?php
/**
 * Template functions for WP Audio Buddy.
 *
 * These are global-namespace functions that theme templates, page builders,
 * and shortcodes can call to retrieve generated content.
 *
 * @package WP Audio Buddy
 */

if (! defined('ABSPATH')) {
    exit;
}

use AdrielPartners\WpAudioBuddy\Data\Meta;
use AdrielPartners\WpAudioBuddy\Data\GeneratedOutputRepository;
use AdrielPartners\WpAudioBuddy\Data\TranscriptRepository;

/**
 * Get the transcript text for an audio attachment.
 *
 * @param int $attachment_id Attachment ID.
 * @return string The transcript text, or empty string if not found.
 */
function wpab_get_transcript(int $attachment_id): string
{
    if (! $attachment_id || ! Meta::is_audio_attachment($attachment_id)) {
        return '';
    }

    $repo = new TranscriptRepository();
    $row = $repo->get_latest_for_attachment($attachment_id);
    if (null !== $row && '' !== trim((string) ($row['transcript_text'] ?? ''))) {
        return (string) $row['transcript_text'];
    }

    return (string) get_post_meta($attachment_id, Meta::TRANSCRIPT, true);
}

/**
 * Get the excerpt/summary text for an audio attachment.
 *
 * @param int $attachment_id Attachment ID.
 * @return string The excerpt text, or empty string if not found.
 */
function wpab_get_excerpt(int $attachment_id): string
{
    if (! $attachment_id || ! Meta::is_audio_attachment($attachment_id)) {
        return '';
    }

    $repo = new GeneratedOutputRepository();
    $row = $repo->get_latest_for_attachment($attachment_id, 'excerpt');
    if (null !== $row && '' !== trim((string) ($row['output_text'] ?? ''))) {
        return (string) $row['output_text'];
    }

    return (string) get_post_meta($attachment_id, Meta::EXCERPT, true);
}

/**
 * Render the transcript for an audio attachment. Returns escaped HTML.
 *
 * @param int $attachment_id Attachment ID.
 * @return string HTML output, or empty string if not found.
 */
function wpab_render_transcript(int $attachment_id): string
{
    $text = wpab_get_transcript($attachment_id);
    if ('' === $text) {
        return '';
    }

    return '<div class="wpab-frontend wpab-transcript">'
        . '<h3 class="wpab-heading">' . esc_html__('Transcript', 'wp-audio-buddy') . '</h3>'
        . wpautop(esc_html($text))
        . '</div>';
}

/**
 * Render the excerpt/summary for an audio attachment. Returns escaped HTML.
 *
 * @param int $attachment_id Attachment ID.
 * @return string HTML output, or empty string if not found.
 */
function wpab_render_excerpt(int $attachment_id): string
{
    $text = wpab_get_excerpt($attachment_id);
    if ('' === $text) {
        return '';
    }

    return '<div class="wpab-frontend wpab-excerpt">'
        . '<h3 class="wpab-heading">' . esc_html__('Summary', 'wp-audio-buddy') . '</h3>'
        . wpautop(esc_html($text))
        . '</div>';
}
