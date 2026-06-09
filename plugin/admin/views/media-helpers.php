<?php
/**
 * View helpers for media attachment fields.
 *
 * These functions render HTML for the attachment edit/modal screens.
 *
 * @package WP Audio Buddy
 */

if (! defined('ABSPATH')) {
    exit;
}

use AdrielPartners\WpAudioBuddy\Data\Meta;

/**
 * Build metadata HTML shown below the transcript textarea.
 */
function wpab_transcript_meta_html(int $attachment_id, ?array $job): string
{
    $parts = [];

    $model = (string) get_post_meta($attachment_id, Meta::TRANSCRIPT_MODEL, true);
    if ('' !== $model) {
        $parts[] = sprintf(
            '<span class="wpab-meta-item">Model: %s</span>',
            esc_html($model)
        );
    }

    $seconds = (int) get_post_meta($attachment_id, Meta::TRANSCRIPT_SECONDS, true);
    if ($seconds > 0) {
        $minutes = round($seconds / 60, 1);
        $parts[] = sprintf(
            '<span class="wpab-meta-item">Duration: %s min</span>',
            esc_html((string) $minutes)
        );
    }

    $updated = (string) get_post_meta($attachment_id, Meta::TRANSCRIPT_UPDATED, true);
    if ('' !== $updated) {
        $parts[] = sprintf(
            '<span class="wpab-meta-item">Generated: %s</span>',
            esc_html($updated)
        );
    }

    if (null !== $job) {
        $job_status_display = esc_html((string) ($job['status'] ?? ''));
        $parts[] = sprintf(
            '<span class="wpab-meta-item">Job: #%d (%s)</span>',
            (int) ($job['id'] ?? 0),
            $job_status_display
        );
    }

    $html = '<div class="wpab-meta">' . implode(' &middot; ', $parts) . '</div>';
    $html .= '<p class="description">' . esc_html__('Editable transcript stored on this attachment.', 'wp-audio-buddy') . '</p>';

    $text = (string) get_post_meta($attachment_id, Meta::TRANSCRIPT, true);
    if (function_exists('mb_strlen') && mb_strlen($text) > 5000) {
        $html .= '<details class="wpab-transcript-collapse"><summary>' . esc_html__('Full transcript (' . mb_strlen($text) . ' characters)', 'wp-audio-buddy') . '</summary><p class="description">' . esc_html__('The full text is shown in the textarea above.', 'wp-audio-buddy') . '</p></details>';
    }

    return $html;
}

/**
 * Build metadata HTML shown below the excerpt textarea.
 */
function wpab_excerpt_meta_html(int $attachment_id): string
{
    $parts = [];

    $model = (string) get_post_meta($attachment_id, Meta::EXCERPT_MODEL, true);
    if ('' !== $model) {
        $parts[] = sprintf(
            '<span class="wpab-meta-item">Model: %s</span>',
            esc_html($model)
        );
    }

    $prompt_type = (string) get_post_meta($attachment_id, Meta::EXCERPT_PROMPT_TYPE, true);
    if ('' !== $prompt_type) {
        $parts[] = sprintf(
            '<span class="wpab-meta-item">Type: %s</span>',
            esc_html($prompt_type)
        );
    }

    $updated = (string) get_post_meta($attachment_id, Meta::EXCERPT_UPDATED, true);
    if ('' !== $updated) {
        $parts[] = sprintf(
            '<span class="wpab-meta-item">Generated: %s</span>',
            esc_html($updated)
        );
    }

    if (! empty($parts)) {
        return '<div class="wpab-meta">' . implode(' &middot; ', $parts) . '</div>'
            . '<p class="description">' . esc_html__('Editable excerpt stored on this attachment.', 'wp-audio-buddy') . '</p>';
    }

    return esc_html__('Editable excerpt stored on this attachment.', 'wp-audio-buddy');
}

/**
 * Build copy-to-clipboard buttons for transcript and excerpt.
 */
function wpab_copy_buttons_html(int $attachment_id): string
{
    $has_transcript = '' !== trim((string) get_post_meta($attachment_id, Meta::TRANSCRIPT, true));
    $has_excerpt = '' !== trim((string) get_post_meta($attachment_id, Meta::EXCERPT, true));
    $has_topics = '' !== trim((string) get_post_meta($attachment_id, Meta::TOPICS, true));

    $html = '<div class="wpab-copy-area">';

    if ($has_transcript) {
        $html .= '<button type="button" class="button wpab-copy-btn" data-attachment="' . esc_attr((string) $attachment_id) . '" data-field="' . esc_attr(Meta::TRANSCRIPT) . '">'
            . esc_html__('Copy Transcription', 'wp-audio-buddy') . '</button> ';
    }

    if ($has_excerpt) {
        $html .= '<button type="button" class="button wpab-copy-btn" data-attachment="' . esc_attr((string) $attachment_id) . '" data-field="' . esc_attr(Meta::EXCERPT) . '">'
            . esc_html__('Copy Excerpt', 'wp-audio-buddy') . '</button> ';
    }

    if ($has_topics) {
        $html .= '<button type="button" class="button wpab-copy-btn" data-attachment="' . esc_attr((string) $attachment_id) . '" data-field="' . esc_attr(Meta::TOPICS) . '">'
            . esc_html__('Copy Topic Tags', 'wp-audio-buddy') . '</button>';
    }

    if (! $has_transcript && ! $has_excerpt && ! $has_topics) {
        $html .= '<span class="description">' . esc_html__('Generate a transcript or excerpt first.', 'wp-audio-buddy') . '</span>';
    }

    $html .= '<span class="wpab-copy-message" aria-live="polite" style="margin-left:6px"></span>';
    $html .= '</div>';

    $html .= <<<'JS'
<script>
(function(){
    var btns = document.querySelectorAll('.wpab-copy-btn');
    btns.forEach(function(btn){
        btn.addEventListener('click', async function(){
            var fieldName = btn.getAttribute('data-field');
            var textarea = document.querySelector('textarea[name*="' + fieldName + '"]');
            if (!textarea) {
                var compat = document.querySelector('.compat-field-' + fieldName + ' textarea');
                if (compat) textarea = compat;
            }
            var msgEl = btn.parentNode.querySelector('.wpab-copy-message');
            if (!textarea || !textarea.value) {
                if (msgEl) msgEl.textContent = 'Nothing to copy.';
                return;
            }
            try {
                await navigator.clipboard.writeText(textarea.value);
                if (msgEl) msgEl.textContent = 'Copied!';
            } catch(e) {
                if (msgEl) msgEl.textContent = 'Copy failed. Select and copy manually.';
            }
        });
    });
})();
</script>
JS;

    return $html;
}

/**
 * Build metadata HTML shown below the topics textarea.
 */
function wpab_topics_meta_html(int $attachment_id): string
{
    $parts = [];

    $model = (string) get_post_meta($attachment_id, Meta::TOPICS_MODEL, true);
    if ('' !== $model) {
        $parts[] = sprintf(
            '<span class="wpab-meta-item">Model: %s</span>',
            esc_html($model)
        );
    }

    $updated = (string) get_post_meta($attachment_id, Meta::TOPICS_UPDATED, true);
    if ('' !== $updated) {
        $parts[] = sprintf(
            '<span class="wpab-meta-item">Generated: %s</span>',
            esc_html($updated)
        );
    }

    $html = '<div class="wpab-meta">' . (empty($parts) ? '' : implode(' &middot; ', $parts)) . '</div>';
    $html .= '<p class="description">' . esc_html__('Editable SEO topic tags stored on this attachment.', 'wp-audio-buddy') . '</p>';

    return $html;
}