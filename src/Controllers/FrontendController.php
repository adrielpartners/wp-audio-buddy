<?php

declare(strict_types=1);

namespace AdrielPartners\WpAudioBuddy\Controllers;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Frontend-facing controllers — shortcodes that expose transcripts and
 * excerpts on the public-facing site.
 */
final class FrontendController
{
    public function __construct()
    {
        add_shortcode('wpab_transcript', [$this, 'shortcode_transcript']);
        add_shortcode('wpab_excerpt', [$this, 'shortcode_excerpt']);
        add_action('wp_enqueue_scripts', [$this, 'assets']);
    }

    /**
     * Enqueue frontend CSS.
     */
    public function assets(): void
    {
        if (! is_admin()) {
            wp_enqueue_style('wpab-frontend', WPAB_URL . 'admin/assets/frontend.css', [], WPAB_VERSION);
        }
    }

    /**
     * Shortcode: [wpab_transcript attachment_id="123"]
     *
     * Displays the transcript for a given audio attachment.
     */
    public function shortcode_transcript(array $atts): string
    {
        $atts = shortcode_atts(['attachment_id' => 0], $atts, 'wpab_transcript');
        $attachment_id = absint($atts['attachment_id']);

        if ($attachment_id <= 0) {
            return '';
        }

        return wpab_render_transcript($attachment_id);
    }

    /**
     * Shortcode: [wpab_excerpt attachment_id="123"]
     *
     * Displays the excerpt/summary for a given audio attachment.
     */
    public function shortcode_excerpt(array $atts): string
    {
        $atts = shortcode_atts(['attachment_id' => 0], $atts, 'wpab_excerpt');
        $attachment_id = absint($atts['attachment_id']);

        if ($attachment_id <= 0) {
            return '';
        }

        return wpab_render_excerpt($attachment_id);
    }
}