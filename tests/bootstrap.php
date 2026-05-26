<?php
/**
 * PHPUnit bootstrap for WP Audio Buddy.
 *
 * To run unit tests without WordPress, install phpunit and run:
 *   cd wp-audio-buddy && phpunit
 *
 * Integration tests require a WordPress test environment:
 *   https://make.wordpress.org/core/handbook/testing/automated-testing/phpunit/
 */

// Composer autoloader for PSR-4 classes.
$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
}

// Load global-scope template functions.
$templates = dirname(__DIR__) . '/src/Support/template-functions.php';
if (file_exists($templates)) {
    require_once $templates;
}

// Define ABSPATH if not in WordPress context (unit tests only).
if (! defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

// Define WPAB constants for tests.
if (! defined('WPAB_FILE')) {
    define('WPAB_FILE', dirname(__DIR__) . '/wp-audio-buddy.php');
}
if (! defined('WPAB_PATH')) {
    define('WPAB_PATH', dirname(__DIR__) . '/');
}
if (! defined('WPAB_URL')) {
    define('WPAB_URL', 'https://example.com/wp-content/plugins/wp-audio-buddy/');
}
if (! defined('WPAB_VERSION')) {
    define('WPAB_VERSION', '0.2.0');
}