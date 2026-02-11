<?php

if (! defined('ABSPATH')) {
    exit;
}

final class WPAB_Editor_UI
{
    public function __construct(private WPAB_Settings $settings)
    {
        add_action('add_meta_boxes', [$this, 'meta_boxes']);
    }

    public function meta_boxes(string $post_type): void
    {
        $allowed = (array) $this->settings->get('editor_post_types', []);
        if (! in_array($post_type, $allowed, true)) {
            return;
        }

        add_meta_box('wpab_editor_copy', __('WP Audio Buddy', 'wp-audio-buddy'), [$this, 'render'], $post_type, 'side', 'default');
    }

    public function render(WP_Post $post): void
    {
        $can_copy_transcript = (bool) $this->settings->get('enable_copy_transcript');
        $can_copy_excerpt = (bool) $this->settings->get('enable_copy_excerpt');

        if (! $can_copy_transcript && ! $can_copy_excerpt) {
            echo '<p>' . esc_html__('Copy tools are disabled in settings.', 'wp-audio-buddy') . '</p>';
            return;
        }

        $attachments = get_children([
            'post_parent' => $post->ID,
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'numberposts' => -1,
            'post_mime_type' => 'audio',
        ]);

        if (empty($attachments)) {
            echo '<p>' . esc_html__('No associated audio attachments found for this post.', 'wp-audio-buddy') . '</p>';
            return;
        }

        $items = [];
        foreach ($attachments as $attachment) {
            $items[] = [
                'id' => $attachment->ID,
                'title' => get_the_title($attachment),
                'transcript' => (string) get_post_meta($attachment->ID, WPAB_Meta::TRANSCRIPT, true),
                'excerpt' => (string) get_post_meta($attachment->ID, WPAB_Meta::EXCERPT, true),
            ];
        }

        echo '<div class="wpab-editor-copy" data-attachments="' . esc_attr(wp_json_encode($items)) . '">';
        if (count($items) > 1) {
            echo '<p><label for="wpab-audio-select"><strong>' . esc_html__('Choose Audio', 'wp-audio-buddy') . '</strong></label><br>';
            echo '<select id="wpab-audio-select" class="widefat">';
            foreach ($items as $item) {
                echo '<option value="' . esc_attr((string) $item['id']) . '">' . esc_html($item['title'] ?: ('#' . $item['id'])) . '</option>';
            }
            echo '</select></p>';
        } else {
            echo '<p>' . esc_html__('Using associated audio: ', 'wp-audio-buddy') . esc_html($items[0]['title']) . '</p>';
        }

        if ($can_copy_transcript) {
            echo '<p><button type="button" class="button wpab-copy-btn" data-type="transcript">' . esc_html__('Copy Audio Transcription', 'wp-audio-buddy') . '</button></p>';
        }
        if ($can_copy_excerpt) {
            echo '<p><button type="button" class="button wpab-copy-btn" data-type="excerpt">' . esc_html__('Copy Audio Excerpt', 'wp-audio-buddy') . '</button></p>';
        }

        echo '<p class="description wpab-copy-message" aria-live="polite"></p>';
        echo '</div>';
        ?>
        <script>
            (function(){
                document.querySelectorAll('.wpab-editor-copy').forEach(function(box){
                    const data = JSON.parse(box.dataset.attachments || '[]');
                    const select = box.querySelector('#wpab-audio-select');
                    const msg = box.querySelector('.wpab-copy-message');
                    const current = function(){
                        if (!data.length) return null;
                        if (!select) return data[0];
                        return data.find(item => String(item.id) === select.value) || data[0];
                    };
                    box.querySelectorAll('.wpab-copy-btn').forEach(function(btn){
                        btn.addEventListener('click', async function(){
                            const item = current();
                            if(!item){ msg.textContent = 'No audio found.'; return; }
                            const type = btn.dataset.type;
                            const text = type === 'excerpt' ? item.excerpt : item.transcript;
                            if(!text){ msg.textContent = 'Nothing available to copy yet.'; return; }
                            try {
                                await navigator.clipboard.writeText(text);
                                msg.textContent = 'Copied ' + type + ' to clipboard.';
                            } catch(e) {
                                msg.textContent = 'Unable to copy. Please copy manually.';
                            }
                        });
                    });
                });
            })();
        </script>
        <?php
    }
}
