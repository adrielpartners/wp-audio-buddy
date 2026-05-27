<div class="wrap wpab-settings">
    <h1><?php esc_html_e('WP Audio Buddy Settings', 'wp-audio-buddy'); ?></h1>
    <form method="post" action="options.php">
        <?php settings_fields($option_key); ?>
        <table class="form-table" role="presentation">
            <tr><th colspan="2"><h2><?php esc_html_e('OpenAI', 'wp-audio-buddy'); ?></h2></th></tr>
            <tr>
                <th><label for="wpab_api_key"><?php esc_html_e('OpenAI API Key', 'wp-audio-buddy'); ?></label></th>
                <td>
                    <input type="password" id="wpab_api_key" class="regular-text" name="wpab_settings[api_key]" value="" autocomplete="off" placeholder="<?php echo esc_attr(! empty($settings['api_key']) ? __('Saved - enter a new key to replace', 'wp-audio-buddy') : ''); ?>">
                    <p class="description"><?php esc_html_e('Leave blank to keep the saved key.', 'wp-audio-buddy'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="wpab_transcription_model"><?php esc_html_e('Transcription model', 'wp-audio-buddy'); ?></label></th>
                <td><?php echo $select_transcription_model; ?></td>
            </tr>
            <tr>
                <th><label for="wpab_excerpt_model"><?php esc_html_e('Excerpt generation model', 'wp-audio-buddy'); ?></label></th>
                <td><?php echo $select_excerpt_model; ?></td>
            </tr>

            <tr><th colspan="2"><h2><?php esc_html_e('Processing Mode', 'wp-audio-buddy'); ?></h2></th></tr>
            <tr>
                <th><?php esc_html_e('Processing mode', 'wp-audio-buddy'); ?></th>
                <td>
                    <fieldset>
                        <label style="display:block;margin-bottom:6px">
                            <input type="radio" name="wpab_settings[processing_mode]" value="auto" <?php checked('auto', $settings['processing_mode']); ?>>
                            <strong><?php esc_html_e('Auto', 'wp-audio-buddy'); ?></strong>
                            &mdash; <?php esc_html_e('Small files process locally on WordPress. Large files are sent to the VPS worker when configured.', 'wp-audio-buddy'); ?>
                        </label>
                        <label style="display:block;margin-bottom:6px">
                            <input type="radio" name="wpab_settings[processing_mode]" value="wordpress_only" <?php checked('wordpress_only', $settings['processing_mode']); ?>>
                            <strong><?php esc_html_e('WordPress Only', 'wp-audio-buddy'); ?></strong>
                            &mdash; <?php esc_html_e('All audio is processed directly on the WordPress server using your OpenAI API key. The worker is never used regardless of file size.', 'wp-audio-buddy'); ?>
                        </label>
                        <label style="display:block;margin-bottom:6px">
                            <input type="radio" name="wpab_settings[processing_mode]" value="worker_only" <?php checked('worker_only', $settings['processing_mode']); ?>>
                            <strong><?php esc_html_e('Worker Only', 'wp-audio-buddy'); ?></strong>
                            &mdash; <?php esc_html_e('All audio is sent to the VPS worker for processing. Requires a configured worker URL and shared secret.', 'wp-audio-buddy'); ?>
                        </label>
                    </fieldset>
                    <p class="description"><?php esc_html_e('Choose how transcription jobs are routed. Auto is recommended for most setups.', 'wp-audio-buddy'); ?></p>
                </td>
            </tr>

            <tr><th colspan="2"><h2><?php esc_html_e('VPS Worker Mode', 'wp-audio-buddy'); ?></h2></th></tr>
            <tr>
                <th><label for="wpab_worker_url"><?php esc_html_e('Worker URL', 'wp-audio-buddy'); ?></label></th>
                <td>
                    <input type="url" id="wpab_worker_url" class="regular-text" name="wpab_settings[worker_url]" value="<?php echo esc_attr($settings['worker_url']); ?>" placeholder="https://worker.example.com/">
                    <p class="description"><?php esc_html_e('When set with a shared secret, transcription requests are delegated to your VPS worker at /v1/transcribe.', 'wp-audio-buddy'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="wpab_worker_shared_secret"><?php esc_html_e('Worker Shared Secret', 'wp-audio-buddy'); ?></label></th>
                <td>
                    <input type="password" id="wpab_worker_shared_secret" class="regular-text" name="wpab_settings[worker_shared_secret]" value="" autocomplete="off" placeholder="<?php echo esc_attr(! empty($settings['worker_shared_secret']) ? __('Saved - enter a new secret to replace', 'wp-audio-buddy') : ''); ?>">
                    <p class="description"><?php esc_html_e('Leave blank to keep the saved worker secret.', 'wp-audio-buddy'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="wpab_worker_chunk_seconds"><?php esc_html_e('Worker chunk seconds', 'wp-audio-buddy'); ?></label></th>
                <td><input type="number" id="wpab_worker_chunk_seconds" min="60" max="900" step="1" name="wpab_settings[worker_chunk_seconds]" value="<?php echo esc_attr((string) $settings['worker_chunk_seconds']); ?>"></td>
            </tr>
            <tr>
                <th><label for="wpab_worker_site_id"><?php esc_html_e('Worker Site ID', 'wp-audio-buddy'); ?></label></th>
                <td>
                    <input type="text" id="wpab_worker_site_id" class="regular-text" name="wpab_settings[worker_site_id]" value="<?php echo esc_attr($settings['worker_site_id']); ?>" placeholder="e.g. site-1">
                    <p class="description"><?php esc_html_e('Optional identifier sent with each job so the worker can distinguish between WordPress sites.', 'wp-audio-buddy'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="wpab_worker_file_size_threshold"><?php esc_html_e('File size threshold (bytes)', 'wp-audio-buddy'); ?></label></th>
                <td>
                    <input type="number" id="wpab_worker_file_size_threshold" min="1048576" max="1073741824" step="1048576" name="wpab_settings[worker_file_size_threshold]" value="<?php echo esc_attr((string) $settings['worker_file_size_threshold']); ?>">
                    <p class="description"><?php esc_html_e('Files larger than this size are sent to the worker in Auto mode. Default: 20971520 (20 MB).', 'wp-audio-buddy'); ?></p>
                </td>
            </tr>

            <tr><th colspan="2"><h2><?php esc_html_e('Automation Toggles', 'wp-audio-buddy'); ?></h2></th></tr>
            <?php echo $checkbox_auto_transcribe; ?>
            <?php echo $checkbox_auto_excerpt; ?>
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
            <?php echo $checkbox_copy_transcript; ?>
            <?php echo $checkbox_copy_excerpt; ?>
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

            <tr><th colspan="2"><h2><?php esc_html_e('Data Management', 'wp-audio-buddy'); ?></h2></th></tr>
            <tr>
                <th><?php esc_html_e('Delete data on uninstall', 'wp-audio-buddy'); ?></th>
                <td>
                    <label><input type="checkbox" name="wpab_settings[delete_data_on_uninstall]" value="1" <?php checked(1, (int) $settings['delete_data_on_uninstall']); ?>> <?php esc_html_e('Remove all plugin data (tables, transcripts, excerpts, settings) when the plugin is deleted.', 'wp-audio-buddy'); ?></label>
                    <p class="description"><?php esc_html_e('By default, plugin data is preserved on uninstall to prevent accidental loss of transcripts and generated content.', 'wp-audio-buddy'); ?></p>
                </td>
            </tr>
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
