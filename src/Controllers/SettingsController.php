<?php

declare(strict_types=1);

namespace AdrielPartners\WpAudioBuddy\Controllers;

use AdrielPartners\WpAudioBuddy\Data\Meta;

if (! defined('ABSPATH')) {
    exit;
}

final class SettingsController
{
    public const OPTION_KEY = 'wpab_settings';

    public function __construct()
    {
        add_action('admin_init', [$this, 'register']);
    }

    public static function defaults(): array
    {
        return [
            'api_key' => '',
            'transcription_model' => 'gpt-4o-mini-transcribe',
            'excerpt_model' => 'gpt-5-mini',
            'auto_transcribe_upload' => 0,
            'auto_generate_excerpt' => 0,
            'auto_format_transcript' => 1,
            'processing_mode' => 'auto',
            'worker_url' => '',
            'worker_site_id' => '',
            'worker_shared_secret' => '',
            'worker_chunk_seconds' => 660,
            'worker_file_size_threshold' => 20971520,
            'excerpt_type' => 'informative',
            'excerpt_prompt_text' => self::prompt_templates()['informative'],
            'excerpt_max_words' => 100,
            'excerpt_temperature' => 0.2,
            'enable_copy_transcript' => 1,
            'enable_copy_excerpt' => 1,
            'editor_post_types' => array_values(get_post_types(['public' => true], 'names')),
            'delete_data_on_uninstall' => 0,
        ];
    }

    public static function prompt_templates(): array
    {
        return [
            'informative' => "You are writing an informative summary of an audio recording.\n\nYour goal is to clearly explain what this audio is about so a reader can quickly understand its main ideas, themes, and takeaways without listening to the full recording.\n\nGuidelines:\n- Be clear, neutral, and accurate.\n- Focus on the core message and key points, not minor details.\n- Do not hype, persuade, or use promotional language.\n- Do not address the reader directly.\n- Do not mention \"this episode,\" \"this podcast,\" or \"this sermon.\"\n- Write in complete sentences and natural paragraphs.\n- Keep the tone factual, calm, and accessible to a general audience.\nLength:\n- Write no more than {{MAX_WORDS}} words.\n\nTranscript:\n{{TRANSCRIPT}}",
            'engaging' => "You are writing an engaging invitation that encourages someone to listen to an audio recording.\n\nYour goal is to spark interest and curiosity while clearly communicating the heart of the message and why it is meaningful or relevant.\n\nGuidelines:\n- Write in a warm, approachable, and conversational tone.\n- Emphasize why the topic matters and what a listener may gain.\n- You may address the reader directly.\n- Avoid hype, exaggeration, or sales language.\n- Do not use clickbait or dramatic claims.\n- Do not mention timestamps, production details, or technical information.\n- Keep the language natural, thoughtful, and inviting.\n\nLength:\n- Write no more than {{MAX_WORDS}} words.\n\nTranscript:\n{{TRANSCRIPT}}",
            'custom' => 'Type your custom writing prompt here.',
        ];
    }

    public function register_menu(string $parent_slug): void
    {
        add_submenu_page($parent_slug, 'Settings', 'Settings', 'manage_options', 'wpab-settings', [$this, 'render']);
    }

    public function register(): void
    {
        register_setting(self::OPTION_KEY, self::OPTION_KEY, [$this, 'sanitize']);
    }

    public function get_all(): array
    {
        return wp_parse_args((array) get_option(self::OPTION_KEY, []), self::defaults());
    }

    public function get(string $key, mixed $fallback = null): mixed
    {
        $all = $this->get_all();
        return $all[$key] ?? $fallback;
    }

    public function sanitize(array $input): array
    {
        $current = $this->get_all();

        $current['api_key'] = sanitize_text_field($input['api_key'] ?? '');
        $current['transcription_model'] = sanitize_text_field($input['transcription_model'] ?? $current['transcription_model']);
        $current['excerpt_model'] = sanitize_text_field($input['excerpt_model'] ?? $current['excerpt_model']);
        $current['auto_transcribe_upload'] = ! empty($input['auto_transcribe_upload']) ? 1 : 0;
        $current['auto_generate_excerpt'] = ! empty($input['auto_generate_excerpt']) ? 1 : 0;
        $current['auto_format_transcript'] = ! empty($input['auto_format_transcript']) ? 1 : 0;

        $mode = sanitize_key($input['processing_mode'] ?? 'auto');
        $current['processing_mode'] = in_array($mode, ['auto', 'wordpress_only', 'worker_only'], true) ? $mode : 'auto';

        $current['worker_url'] = esc_url_raw($input['worker_url'] ?? '');
        $current['worker_site_id'] = sanitize_key($input['worker_site_id'] ?? '');
        $current['worker_shared_secret'] = sanitize_text_field($input['worker_shared_secret'] ?? '');
        $current['worker_chunk_seconds'] = max(60, min(900, absint($input['worker_chunk_seconds'] ?? 660)));
        $current['worker_file_size_threshold'] = absint($input['worker_file_size_threshold'] ?? 20971520);
        $current['worker_file_size_threshold'] = max(1048576, min(1073741824, $current['worker_file_size_threshold']));

        $current['excerpt_type'] = sanitize_text_field($input['excerpt_type'] ?? 'informative');
        $current['excerpt_prompt_text'] = sanitize_textarea_field($input['excerpt_prompt_text'] ?? self::prompt_templates()[$current['excerpt_type']] ?? '');
        $current['excerpt_max_words'] = max(10, absint($input['excerpt_max_words'] ?? 100));
        $current['excerpt_temperature'] = max(0, min(1, (float) ($input['excerpt_temperature'] ?? 0.2)));
        $current['enable_copy_transcript'] = ! empty($input['enable_copy_transcript']) ? 1 : 0;
        $current['enable_copy_excerpt'] = ! empty($input['enable_copy_excerpt']) ? 1 : 0;

        $post_types = array_values(array_map('sanitize_key', (array) ($input['editor_post_types'] ?? [])));
        $public = array_values(get_post_types(['public' => true], 'names'));
        $current['editor_post_types'] = array_values(array_intersect($public, $post_types));

        $current['delete_data_on_uninstall'] = ! empty($input['delete_data_on_uninstall']) ? 1 : 0;

        return $current;
    }

    public function render(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $settings = $this->get_all();
        $usage = $this->usage_stats();
        $public_post_types = get_post_types(['public' => true], 'objects');
        $templates = self::prompt_templates();

        // Pre-render helper HTML for the view template.
        $select_transcription_model = $this->select('transcription_model', ['gpt-4o-mini-transcribe', 'gpt-4o-transcribe', 'whisper-1'], $settings['transcription_model']);
        $select_excerpt_model = $this->select('excerpt_model', ['gpt-5-nano', 'gpt-5-mini', 'gpt-5.1', 'gpt-5.2'], $settings['excerpt_model']);
        $checkbox_auto_transcribe = $this->checkbox_row('auto_transcribe_upload', 'Auto-transcribe audio on upload (MP3 by default)', $settings);
        $checkbox_auto_excerpt = $this->checkbox_row('auto_generate_excerpt', 'Auto-generate excerpt after transcription', $settings);
        $checkbox_copy_transcript = $this->checkbox_row('enable_copy_transcript', 'Enable "Copy Audio Transcription" button in post editors', $settings);
        $checkbox_copy_excerpt = $this->checkbox_row('enable_copy_excerpt', 'Enable "Copy Audio Excerpt" button in post editors', $settings);

        $option_key = self::OPTION_KEY;
        include WPAB_PATH . 'admin/views/settings-page.php';
    }

    private function select(string $name, array $options, string $current): string
    {
        $html = '<select id="wpab_' . esc_attr($name) . '" name="wpab_settings[' . esc_attr($name) . ']">';
        foreach ($options as $option) {
            $html .= '<option value="' . esc_attr($option) . '" ' . selected($option, $current, false) . '>' . esc_html($option) . '</option>';
        }
        $html .= '</select>';
        return $html;
    }

    private function checkbox_row(string $key, string $label, array $settings): string
    {
        return '<tr><th>' . esc_html($label) . '</th><td><label><input type="checkbox" name="wpab_settings[' . esc_attr($key) . ']" value="1" ' . checked(1, (int) $settings[$key], false) . '> ' . esc_html__('Enable', 'wp-audio-buddy') . '</label></td></tr>';
    }

    private function usage_stats(): array
    {
        global $wpdb;
        $seconds = (int) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(CAST(pm.meta_value AS UNSIGNED)),0) FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID=pm.post_id WHERE pm.meta_key=%s AND p.post_type='attachment'", Meta::TRANSCRIPT_SECONDS));
        $excerpts = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID=pm.post_id WHERE pm.meta_key=%s AND pm.meta_value<>'' AND p.post_type='attachment'", Meta::EXCERPT));
        $minutes_total = (float) get_option('wpab_total_minutes_transcribed', 0);
        return ['minutes' => max(round($seconds / 60, 2), round($minutes_total, 2)), 'excerpts' => $excerpts];
    }
}