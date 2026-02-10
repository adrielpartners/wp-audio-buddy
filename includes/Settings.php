<?php

if (! defined('ABSPATH')) {
    exit;
}

final class WPAB_Settings
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
            'excerpt_model' => 'gpt-5-nano',
            'auto_transcribe_upload' => 0,
            'auto_generate_excerpt' => 0,
            'auto_format_transcript' => 1,
            'excerpt_type' => 'informative',
            'excerpt_prompt_text' => self::prompt_templates()['informative'],
            'excerpt_max_words' => 100,
            'excerpt_temperature' => 0.2,
            'enable_copy_transcript' => 1,
            'enable_copy_excerpt' => 1,
            'editor_post_types' => array_values(get_post_types(['public' => true], 'names')),
        ];
    }

    public static function prompt_templates(): array
    {
        return [
            'informative' => "You are writing an informative summary of an audio recording.\n\nYour goal is to clearly explain what this audio is about so a reader can quickly understand its main ideas, themes, and takeaways without listening to the full recording.\n\nGuidelines:\n- Be clear, neutral, and accurate.\n- Focus on the core message and key points, not minor details.\n- Do not hype, persuade, or use promotional language.\n- Do not address the reader directly.\n- Do not mention “this episode,” “this podcast,” or “this sermon.”\n- Write in complete sentences and natural paragraphs.\n- Keep the tone factual, calm, and accessible to a general audience.\nLength:\n- Write no more than {{MAX_WORDS}} words.\n\nTranscript:\n{{TRANSCRIPT}}",
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
        $current['excerpt_type'] = sanitize_text_field($input['excerpt_type'] ?? 'informative');
        $current['excerpt_prompt_text'] = sanitize_textarea_field($input['excerpt_prompt_text'] ?? self::prompt_templates()[$current['excerpt_type']] ?? '');
        $current['excerpt_max_words'] = max(10, absint($input['excerpt_max_words'] ?? 100));
        $current['excerpt_temperature'] = max(0, min(1, (float) ($input['excerpt_temperature'] ?? 0.2)));
        $current['enable_copy_transcript'] = ! empty($input['enable_copy_transcript']) ? 1 : 0;
        $current['enable_copy_excerpt'] = ! empty($input['enable_copy_excerpt']) ? 1 : 0;

        $post_types = array_values(array_map('sanitize_key', (array) ($input['editor_post_types'] ?? [])));
        $public = array_values(get_post_types(['public' => true], 'names'));
        $current['editor_post_types'] = array_values(array_intersect($public, $post_types));

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
        ?>
        <div class="wrap wpab-settings">
            <h1><?php esc_html_e('WP Audio Buddy Settings', 'wp-audio-buddy'); ?></h1>
            <form method="post" action="options.php">
                <?php settings_fields(self::OPTION_KEY); ?>
                <table class="form-table" role="presentation">
                    <tr><th colspan="2"><h2><?php esc_html_e('OpenAI', 'wp-audio-buddy'); ?></h2></th></tr>
                    <tr>
                        <th><label for="wpab_api_key"><?php esc_html_e('OpenAI API Key', 'wp-audio-buddy'); ?></label></th>
                        <td><input type="password" id="wpab_api_key" class="regular-text" name="wpab_settings[api_key]" value="<?php echo esc_attr($settings['api_key']); ?>" autocomplete="off"></td>
                    </tr>
                    <tr>
                        <th><label for="wpab_transcription_model"><?php esc_html_e('Transcription model', 'wp-audio-buddy'); ?></label></th>
                        <td><?php $this->select('transcription_model', ['gpt-4o-mini-transcribe', 'gpt-4o-transcribe', 'whisper-1'], $settings['transcription_model']); ?></td>
                    </tr>
                    <tr>
                        <th><label for="wpab_excerpt_model"><?php esc_html_e('Excerpt generation model', 'wp-audio-buddy'); ?></label></th>
                        <td><?php $this->select('excerpt_model', ['gpt-5-nano', 'gpt-5-mini', 'gpt-5.1', 'gpt-5.2'], $settings['excerpt_model']); ?></td>
                    </tr>
                    <tr><th colspan="2"><h2><?php esc_html_e('Automation Toggles', 'wp-audio-buddy'); ?></h2></th></tr>
                    <?php $this->checkbox_row('auto_transcribe_upload', 'Auto-transcribe audio on upload (MP3 by default)', $settings); ?>
                    <?php $this->checkbox_row('auto_generate_excerpt', 'Auto-generate excerpt after transcription', $settings); ?>
                    <tr>
                        <th><?php esc_html_e('Auto-format transcription into paragraphs', 'wp-audio-buddy'); ?></th>
                        <td>
                            <label><input type="checkbox" name="wpab_settings[auto_format_transcript]" value="1" <?php checked(1, (int) $settings['auto_format_transcript']); ?>> <?php esc_html_e('Enable', 'wp-audio-buddy'); ?></label>
                            <p class="description"><?php esc_html_e('Uses more tokens from your excerpt model', 'wp-audio-buddy'); ?></p>
                        </td>
                    </tr>

                    <tr><th colspan="2"><h2><?php esc_html_e('Excerpt Defaults', 'wp-audio-buddy'); ?></h2></th></tr>
                    <tr>
                        <th><?php esc_html_e('Excerpt type', 'wp-audio-buddy'); ?></th>
                        <td>
                            <select id="wpab_excerpt_type" name="wpab_settings[excerpt_type]">
                                <option value="informative" <?php selected('informative', $settings['excerpt_type']); ?>><?php esc_html_e('Informative Summary', 'wp-audio-buddy'); ?></option>
                                <option value="engaging" <?php selected('engaging', $settings['excerpt_type']); ?>><?php esc_html_e('Engaging Invitation', 'wp-audio-buddy'); ?></option>
                                <option value="custom" <?php selected('custom', $settings['excerpt_type']); ?>><?php esc_html_e('Custom Prompt', 'wp-audio-buddy'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Excerpt writing prompt', 'wp-audio-buddy'); ?></th>
                        <td>
                            <textarea id="wpab_excerpt_prompt_text" class="large-text code" rows="14" name="wpab_settings[excerpt_prompt_text]"><?php echo esc_textarea($settings['excerpt_prompt_text']); ?></textarea>
                            <p class="description"><?php esc_html_e('Template supports {{MAX_WORDS}} and {{TRANSCRIPT}}.', 'wp-audio-buddy'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Max length (words)', 'wp-audio-buddy'); ?></th>
                        <td><input type="number" min="10" step="1" name="wpab_settings[excerpt_max_words]" value="<?php echo esc_attr((string) $settings['excerpt_max_words']); ?>"></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Temperature', 'wp-audio-buddy'); ?></th>
                        <td><input type="number" min="0" max="1" step="0.1" name="wpab_settings[excerpt_temperature]" value="<?php echo esc_attr((string) $settings['excerpt_temperature']); ?>"></td>
                    </tr>

                    <tr><th colspan="2"><h2><?php esc_html_e('Editor Integration', 'wp-audio-buddy'); ?></h2></th></tr>
                    <?php $this->checkbox_row('enable_copy_transcript', 'Enable “Copy Audio Transcription” button in post editors', $settings); ?>
                    <?php $this->checkbox_row('enable_copy_excerpt', 'Enable “Copy Audio Excerpt” button in post editors', $settings); ?>
                    <tr>
                        <th><?php esc_html_e('Post types', 'wp-audio-buddy'); ?></th>
                        <td>
                            <?php foreach ($public_post_types as $pt) : ?>
                                <label style="display:block"><input type="checkbox" name="wpab_settings[editor_post_types][]" value="<?php echo esc_attr($pt->name); ?>" <?php checked(in_array($pt->name, $settings['editor_post_types'], true)); ?>><?php echo esc_html($pt->label); ?></label>
                            <?php endforeach; ?>
                        </td>
                    </tr>

                    <tr><th colspan="2"><h2><?php esc_html_e('Usage Tracking (Read-only)', 'wp-audio-buddy'); ?></h2></th></tr>
                    <tr><th><?php esc_html_e('Total minutes transcribed', 'wp-audio-buddy'); ?></th><td><?php echo esc_html((string) $usage['minutes']); ?></td></tr>
                    <tr><th><?php esc_html_e('Total excerpts generated', 'wp-audio-buddy'); ?></th><td><?php echo esc_html((string) $usage['excerpts']); ?></td></tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <script>
            (function(){
                const templates = <?php echo wp_json_encode($templates); ?>;
                const type = document.getElementById('wpab_excerpt_type');
                const textarea = document.getElementById('wpab_excerpt_prompt_text');
                if(!type || !textarea) return;

                type.addEventListener('change', function(){
                    textarea.value = templates[type.value] || templates.custom;
                });
            })();
        </script>
        <?php
    }

    private function select(string $name, array $options, string $current): void
    {
        echo '<select id="wpab_' . esc_attr($name) . '" name="wpab_settings[' . esc_attr($name) . ']">';
        foreach ($options as $option) {
            echo '<option value="' . esc_attr($option) . '" ' . selected($option, $current, false) . '>' . esc_html($option) . '</option>';
        }
        echo '</select>';
    }

    private function checkbox_row(string $key, string $label, array $settings): void
    {
        echo '<tr><th>' . esc_html($label) . '</th><td><label><input type="checkbox" name="wpab_settings[' . esc_attr($key) . ']" value="1" ' . checked(1, (int) $settings[$key], false) . '> ' . esc_html__('Enable', 'wp-audio-buddy') . '</label></td></tr>';
    }

    private function usage_stats(): array
    {
        global $wpdb;
        $seconds = (int) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(CAST(pm.meta_value AS UNSIGNED)),0) FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID=pm.post_id WHERE pm.meta_key=%s AND p.post_type='attachment'", WPAB_Meta::TRANSCRIPT_SECONDS));
        $excerpts = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID=pm.post_id WHERE pm.meta_key=%s AND pm.meta_value<>'' AND p.post_type='attachment'", WPAB_Meta::EXCERPT));
        return ['minutes' => round($seconds / 60, 2), 'excerpts' => $excerpts];
    }
}
