<?php

declare(strict_types=1);

namespace AdrielPartners\WpAudioBuddy;

use AdrielPartners\WpAudioBuddy\Controllers\BulkToolsController;
use AdrielPartners\WpAudioBuddy\Controllers\EditorController;
use AdrielPartners\WpAudioBuddy\Controllers\FrontendController;
use AdrielPartners\WpAudioBuddy\Controllers\LogsController;
use AdrielPartners\WpAudioBuddy\Controllers\MediaController;
use AdrielPartners\WpAudioBuddy\Controllers\SettingsController;
use AdrielPartners\WpAudioBuddy\Controllers\WorkerCallbackController;
use AdrielPartners\WpAudioBuddy\Data\GeneratedOutputRepository;
use AdrielPartners\WpAudioBuddy\Data\JobRepository;
use AdrielPartners\WpAudioBuddy\Data\LoggerRepository;
use AdrielPartners\WpAudioBuddy\Data\Schema;
use AdrielPartners\WpAudioBuddy\Data\TranscriptRepository;
use AdrielPartners\WpAudioBuddy\Integrations\WorkerClient;
use AdrielPartners\WpAudioBuddy\Services\ExcerptService;
use AdrielPartners\WpAudioBuddy\Services\QueueService;
use AdrielPartners\WpAudioBuddy\Services\TranscriptionService;
use AdrielPartners\WpAudioBuddy\Support\AudioChunker;

if (! defined('ABSPATH')) {
    exit;
}

final class Plugin
{
    private static ?Plugin $instance = null;

    private ?SettingsController $settings = null;
    private ?LoggerRepository $logger = null;
    private ?AudioChunker $chunker = null;
    private ?QueueService $queue = null;
    private ?ExcerptService $excerpt_service = null;
    private ?TranscriptionService $transcription_service = null;
    private ?JobRepository $jobs = null;
    private ?TranscriptRepository $transcripts = null;
    private ?WorkerClient $worker = null;
    private ?GeneratedOutputRepository $outputs = null;

    public static function instance(): Plugin
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
        $logger = new LoggerRepository();
        $logger->create_table();

        $schema = new Schema();
        $schema->install();

        if (! get_option(SettingsController::OPTION_KEY)) {
            add_option(SettingsController::OPTION_KEY, SettingsController::defaults());
        }
    }

    public function boot(): void
    {
        $schema = new Schema();
        if ($schema->needs_update()) {
            $schema->install();
        }

        // Load global-scope template functions.
        require_once WPAB_PATH . 'src/Support/template-functions.php';
        // Load view helper functions.
        require_once WPAB_PATH . 'admin/views/media-helpers.php';

        $this->settings = new SettingsController();
        $this->logger = new LoggerRepository();
        $this->chunker = new AudioChunker();
        $this->jobs = new JobRepository();
        $this->transcripts = new TranscriptRepository();
        $this->worker = new WorkerClient($this->settings, $this->logger);
        $this->outputs = new GeneratedOutputRepository();
        $this->queue = new QueueService($this->settings, $this->logger, $this->jobs);
        $this->excerpt_service = new ExcerptService($this->settings, $this->logger, $this->outputs, $this->jobs);
        $this->transcription_service = new TranscriptionService(
            $this->settings,
            $this->queue,
            $this->excerpt_service,
            $this->logger,
            $this->chunker,
            $this->jobs,
            $this->transcripts,
            $this->worker
        );

        $bulk_tools = new BulkToolsController($this->queue, $this->logger, $this->jobs);
        $logs_page = new LogsController($this->logger);
        new WorkerCallbackController($this->settings, $this->logger, $this->transcription_service, $this->jobs);

        add_action('admin_menu', function () use ($logs_page): void {
            $this->settings->register_menu(BulkToolsController::PARENT_SLUG);
            $logs_page->register_menu(BulkToolsController::PARENT_SLUG);
        }, 20);

        new MediaController($this->settings, $this->queue, $this->logger, $this->jobs, $this->transcripts, $this->outputs);
        new EditorController($this->settings);
        new FrontendController();

        $this->queue->register_handlers($this->transcription_service, $this->excerpt_service);

        if (is_admin() && isset($_GET['page']) && str_starts_with(sanitize_key($_GET['page']), 'wpab')) {
            $this->logger->info('plugin_boot', 'WP Audio Buddy booted.');
        }
    }
}
