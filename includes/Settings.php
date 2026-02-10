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
        add_action('admin_menu', [$this, 'menu']);
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
            'auto_transcribe_mimetypes' => ['audio/mpeg'],
            'excerpt_type' => 'informative',
            'excerpt_custom_prompt' => '',
            'excerpt_max_words' => 100,
            'excerpt_temperature' => 0.2,
            'enable_copy_transcript' => 1,
            'enable_copy_excerpt' => 1,
            'editor_post_types' => array_values(get_post_types(['public' => true], 'names')),
        ];
    }

    public function menu(): void
    {
        add_options_page(
            __('WP Audio Buddy', 'wp-audio-buddy'),
            __('WP Audio Buddy', 'wp-audio-buddy'),
            'manage_options',
            'wp-audio-buddy',
            [$this, 'render']
        );
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
        $current['excerpt_custom_prompt'] = sanitize_textarea_field($input['excerpt_custom_prompt'] ?? '');
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
        ?>
        <div class="wrap wpab-settings">
            <h1><?php esc_html_e('WP Audio Buddy', 'wp-audio-buddy'); ?></h1>
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
                    <tr class="wpab-custom-prompt-row">
                        <th><?php esc_html_e('Custom prompt', 'wp-audio-buddy'); ?></th>
                        <td><textarea class="large-text code" rows="5" name="wpab_settings[excerpt_custom_prompt]"><?php echo esc_textarea($settings['excerpt_custom_prompt']); ?></textarea></td>
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
                const type = document.getElementById('wpab_excerpt_type');
                const row = document.querySelector('.wpab-custom-prompt-row');
                function toggle(){ if(!type||!row) return; row.style.display = type.value === 'custom' ? '' : 'none'; }
                if(type){ type.addEventListener('change', toggle); toggle(); }
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
        return [
            'minutes' => round($seconds / 60, 2),
            'excerpts' => $excerpts,
        ];
    }
}
