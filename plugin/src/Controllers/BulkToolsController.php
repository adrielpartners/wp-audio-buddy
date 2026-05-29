<?php

declare(strict_types=1);

namespace AdrielPartners\WpAudioBuddy\Controllers;

use AdrielPartners\WpAudioBuddy\Data\JobRepository;
use AdrielPartners\WpAudioBuddy\Data\LoggerRepository;
use AdrielPartners\WpAudioBuddy\Data\Meta;
use AdrielPartners\WpAudioBuddy\Services\QueueService;

if (! defined('ABSPATH')) {
    exit;
}

final class BulkToolsController
{
    public const PARENT_SLUG = 'wpab';

    public function __construct(
        private QueueService $queue,
        private LoggerRepository $logger,
        private JobRepository $jobs
    ) {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_post_wpab_bulk_transcribe', [$this, 'bulk_transcribe']);
        add_action('admin_post_wpab_bulk_excerpt', [$this, 'bulk_excerpt']);
    }

    public function menu(): void
    {
        add_menu_page('WP Audio Buddy', 'WP Audio Buddy', 'manage_options', self::PARENT_SLUG, [$this, 'render'], 'dashicons-format-audio', 81);
        add_submenu_page(self::PARENT_SLUG, 'Bulk Tools', 'Bulk Tools', 'manage_options', self::PARENT_SLUG, [$this, 'render']);
    }

    public function render(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $counts = $this->counts();
        ?>
        <div class="wrap wpab-bulk-tools">
            <h1><?php esc_html_e('WP Audio Buddy Bulk Tools', 'wp-audio-buddy'); ?></h1>
            <p>
                <a class="button button-primary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=wpab_bulk_transcribe'), 'wpab_bulk_transcribe')); ?>"><?php esc_html_e('Queue transcription for all un-transcribed audio attachments', 'wp-audio-buddy'); ?></a>
            </p>
            <p>
                <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=wpab_bulk_excerpt'), 'wpab_bulk_excerpt')); ?>"><?php esc_html_e('Queue excerpt generation for all attachments with transcripts', 'wp-audio-buddy'); ?></a>
            </p>

            <h2><?php esc_html_e('Counters', 'wp-audio-buddy'); ?></h2>
            <ul>
                <li><strong><?php esc_html_e('Total audio files:', 'wp-audio-buddy'); ?></strong> <?php echo esc_html((string) $counts['audio']); ?></li>
                <li><strong><?php esc_html_e('Queued:', 'wp-audio-buddy'); ?></strong> <?php echo esc_html((string) $counts['queued']); ?></li>
                <li><strong><?php esc_html_e('Completed:', 'wp-audio-buddy'); ?></strong> <?php echo esc_html((string) $counts['completed']); ?></li>
                <li><strong><?php esc_html_e('Errors:', 'wp-audio-buddy'); ?></strong> <?php echo esc_html((string) $counts['errors']); ?></li>
            </ul>
        </div>
        <?php
    }

    public function bulk_transcribe(): void
    {
        $this->guard('wpab_bulk_transcribe');

        $attachments = get_posts([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => -1,
            'post_mime_type' => ['audio/mpeg', 'audio/mp3', 'audio/mp4', 'audio/x-m4a', 'audio/wav', 'audio/x-wav'],
            'fields' => 'ids',
            'meta_query' => [
                'relation' => 'OR',
                ['key' => Meta::TRANSCRIPT, 'compare' => 'NOT EXISTS'],
                ['key' => Meta::TRANSCRIPT, 'value' => '', 'compare' => '='],
            ],
        ]);

        foreach ($attachments as $id) {
            $this->queue->enqueue_transcription((int) $id);
        }

        $this->logger->info('bulk_transcribe', 'Queued transcription jobs.', null, ['count' => count($attachments)]);
        wp_safe_redirect(admin_url('admin.php?page=' . self::PARENT_SLUG));
        exit;
    }

    public function bulk_excerpt(): void
    {
        $this->guard('wpab_bulk_excerpt');

        $attachments = get_posts([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => -1,
            'post_mime_type' => ['audio/mpeg', 'audio/mp3', 'audio/mp4', 'audio/x-m4a', 'audio/wav', 'audio/x-wav'],
            'fields' => 'ids',
            'meta_query' => [['key' => Meta::TRANSCRIPT, 'value' => '', 'compare' => '!=']],
        ]);

        foreach ($attachments as $id) {
            $this->queue->enqueue_excerpt((int) $id);
        }

        $this->logger->info('bulk_excerpt', 'Queued excerpt jobs.', null, ['count' => count($attachments)]);
        wp_safe_redirect(admin_url('admin.php?page=' . self::PARENT_SLUG));
        exit;
    }

    private function counts(): array
    {
        global $wpdb;

        $audio = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='attachment' AND post_mime_type LIKE 'audio/%'");
        $pm_queued = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key IN (%s,%s) AND meta_value='queued'", Meta::TRANSCRIPT_STATUS, Meta::EXCERPT_STATUS));
        $pm_completed = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key IN (%s,%s) AND meta_value='done'", Meta::TRANSCRIPT_STATUS, Meta::EXCERPT_STATUS));
        $pm_errors = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key IN (%s,%s) AND meta_value='error'", Meta::TRANSCRIPT_STATUS, Meta::EXCERPT_STATUS));

        $queued = max($pm_queued, $this->jobs->count_by_status('queued'));
        $completed = max($pm_completed, $this->jobs->count_by_status('completed'));
        $errors = max($pm_errors, $this->jobs->count_by_status('failed') + $this->jobs->count_by_status('retryable') + $this->jobs->count_by_status('cancelled'));

        return compact('audio', 'queued', 'completed', 'errors');
    }

    private function guard(string $nonce_action): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Permission denied.', 'wp-audio-buddy'));
        }

        check_admin_referer($nonce_action);
    }
}