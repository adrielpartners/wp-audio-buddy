<?php
/**
 * Plugin Name: WP Audio Buddy
 * Description: Transcribe audio attachments with OpenAI and generate reusable AI excerpts.
 * Version: 0.1.0
 * Author: WP Audio Buddy
 * Requires PHP: 8.0
 */

if (! defined('ABSPATH')) {
    exit;
}

define('WPAB_VERSION', '0.1.0');
define('WPAB_FILE', __FILE__);
define('WPAB_PATH', plugin_dir_path(__FILE__));
define('WPAB_URL', plugin_dir_url(__FILE__));

require_once WPAB_PATH . 'includes/Meta.php';
require_once WPAB_PATH . 'includes/Settings.php';
require_once WPAB_PATH . 'includes/Queue.php';
require_once WPAB_PATH . 'includes/TranscriptionService.php';
require_once WPAB_PATH . 'includes/ExcerptService.php';
require_once WPAB_PATH . 'includes/MediaUI.php';
require_once WPAB_PATH . 'includes/EditorUI.php';
require_once WPAB_PATH . 'includes/BulkTools.php';

final class WP_Audio_Buddy
{
    private static ?WP_Audio_Buddy $instance = null;

    public static function instance(): WP_Audio_Buddy
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
        register_activation_hook(WPAB_FILE, [$this, 'activate']);

        add_action('plugins_loaded', [$this, 'boot']);
    }

    public function activate(): void
    {
        if (! get_option(WPAB_Settings::OPTION_KEY)) {
            add_option(WPAB_Settings::OPTION_KEY, WPAB_Settings::defaults());
        }
    }

    public function boot(): void
    {
        $settings = new WPAB_Settings();
        $queue = new WPAB_Queue($settings);
        $excerpt_service = new WPAB_ExcerptService($settings);
        $transcription_service = new WPAB_TranscriptionService($settings, $queue, $excerpt_service);

        new WPAB_Media_UI($settings, $queue);
        new WPAB_Editor_UI($settings);
        new WPAB_Bulk_Tools($queue);

        $queue->register_handlers($transcription_service, $excerpt_service);
    }
}

WP_Audio_Buddy::instance();
