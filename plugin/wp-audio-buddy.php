<?php
/**
 * Plugin Name: WP Audio Buddy
 * Description: Transcribe audio attachments with OpenAI and generate reusable AI excerpts.
 * Version: 0.2.1
 * Author: WP Audio Buddy
 * Requires PHP: 8.0
 */

if (! defined('ABSPATH')) {
    exit;
}

define('WPAB_VERSION', '0.2.1');
define('WPAB_FILE', __FILE__);
define('WPAB_PATH', plugin_dir_path(__FILE__));
define('WPAB_URL', plugin_dir_url(__FILE__));

$autoload = WPAB_PATH . 'vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
} else {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'AdrielPartners\\WpAudioBuddy\\';
        if (! str_starts_with($class, $prefix)) {
            return;
        }

        $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
        $file = WPAB_PATH . 'src/' . $relative . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    });
}

\AdrielPartners\WpAudioBuddy\Plugin::instance();
