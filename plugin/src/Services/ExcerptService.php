<?php

declare(strict_types=1);

namespace AdrielPartners\WpAudioBuddy\Services;

use AdrielPartners\WpAudioBuddy\Controllers\SettingsController;
use AdrielPartners\WpAudioBuddy\Data\GeneratedOutputRepository;
use AdrielPartners\WpAudioBuddy\Data\JobRepository;
use AdrielPartners\WpAudioBuddy\Data\LoggerRepository;
use AdrielPartners\WpAudioBuddy\Data\Meta;
use AdrielPartners\WpAudioBuddy\Data\TranscriptRepository;
use AdrielPartners\WpAudioBuddy\Integrations\Providers\OpenAIProvider;
use AdrielPartners\WpAudioBuddy\Integrations\ProviderRegistry;

if (! defined('ABSPATH')) {
    exit;
}

final class ExcerptService
{
public function __construct(
        private SettingsController $settings,
        private LoggerRepository $logger,
        private GeneratedOutputRepository $outputs,
        private JobRepository $jobs
    ) {
    }

    public function handle(int $attachment_id, ?int $job_id = null): void
    {
        try {
        $config = $this->settings->getProviderConfig('excerpt');
        $transcript_repo = new TranscriptRepository();
        $latest_transcript = $transcript_repo->get_latest_for_attachment($attachment_id);
        $transcript = null !== $latest_transcript
            ? (string) ($latest_transcript['transcript_text'] ?? '')
            : (string) get_post_meta($attachment_id, Meta::TRANSCRIPT, true);
        if ('' === trim($transcript)) {
            $this->logger->info('excerpt', 'Skipped excerpt generation: transcript missing.', $attachment_id);
            $this->fail_job($attachment_id, $job_id, 'Transcript is missing.');
            return;
        }

        if ('done' === Meta::excerpt_status($attachment_id) && '' !== trim((string) get_post_meta($attachment_id, Meta::EXCERPT, true))) {
            $this->logger->info('excerpt', 'Skipped excerpt generation: already complete.', $attachment_id);
            $this->complete_job($attachment_id, $job_id);
            return;
        }

        update_post_meta($attachment_id, Meta::EXCERPT_STATUS, 'running');
        $this->update_job_status($attachment_id, $job_id, 'running', ['started_at' => current_time('mysql')]);

        $prompt_type = (string) $this->settings->get('excerpt_type', 'informative');
        $max_words = (int) $this->settings->get('excerpt_max_words', 100);
        $temperature = $this->settings->get('excerpt_temperature', null);
        $template = (string) $this->settings->get('excerpt_prompt_text', SettingsController::prompt_templates()[$prompt_type] ?? '');

        $prompt = str_replace(['{{MAX_WORDS}}', '{{TRANSCRIPT}}'], [(string) $max_words, $transcript], $template);
        $response = $this->responses_api($prompt, $max_words, $temperature);

        if (is_wp_error($response)) {
            $this->handle_excerpt_error($attachment_id, $response, $job_id);
            return;
        }

        update_post_meta($attachment_id, Meta::EXCERPT, $response);
        update_post_meta($attachment_id, Meta::EXCERPT_ERROR, '');
        update_post_meta($attachment_id, Meta::EXCERPT_STATUS, 'done');
        update_post_meta($attachment_id, Meta::EXCERPT_MODEL, $config['model']);
        update_post_meta($attachment_id, Meta::EXCERPT_PROMPT_TYPE, $prompt_type);
        update_post_meta($attachment_id, Meta::EXCERPT_PROMPT_CUSTOM, $template);
        update_post_meta($attachment_id, Meta::EXCERPT_UPDATED, current_time('mysql'));

        $this->outputs->insert([
            'attachment_id' => $attachment_id,
            'job_id' => $job_id,
            'output_type' => 'excerpt',
            'prompt_type' => $prompt_type,
            'output_text' => $response,
            'metadata_json' => wp_json_encode([
                'model' => $config['model'],
                'max_words' => $max_words,
            ]),
        ]);

        $this->complete_job($attachment_id, $job_id);
        $this->logger->info('excerpt', 'Excerpt generated successfully.', $attachment_id, ['prompt_type' => $prompt_type]);
        } catch (\Throwable $e) {
            $message = 'Excerpt error: ' . $e->getMessage();
            $this->logger->error('excerpt_exception', $message, $attachment_id, ['file' => $e->getFile(), 'line' => $e->getLine()]);
            $this->fail_job($attachment_id, $job_id, $message);
        }
    }

    /**
     * Handle an excerpt API error with bounded retry for transient failures.
     */
    private function handle_excerpt_error(int $attachment_id, \WP_Error $error, ?int $job_id = null): void
    {
        $message = $error->get_error_message();

        if (OpenAIProvider::is_transient_error($error)) {
            $job = $this->current_job($attachment_id, $job_id);
            $attempts = 0;
            if ($job !== null && ($job['operation'] ?? '') === 'excerpt') {
                $attempts = (int) ($job['attempt_count'] ?? 0);
            }

            if ($attempts < OpenAIProvider::MAX_RETRIES) {
                if ($job !== null) {
                    $job_id = (int) $job['id'];
                    $this->jobs->update((int) $job['id'], [
                        'attempt_count' => $attempts + 1,
                        'status' => 'queued',
                    ]);
                }
                update_post_meta($attachment_id, Meta::EXCERPT_STATUS, 'queued');
                $this->logger->info('excerpt_retry', 'Retrying excerpt after transient error.', $attachment_id, [
                    'attempt' => $attempts + 1,
                    'max' => OpenAIProvider::MAX_RETRIES,
                    'error' => $message,
                ]);
                if (function_exists('as_enqueue_async_action')) {
                    as_enqueue_async_action('wpab_generate_excerpt', [$attachment_id, $job_id], 'wp-audio-buddy');
                } elseif (! wp_next_scheduled('wpab_generate_excerpt', [$attachment_id, $job_id])) {
                    wp_schedule_single_event(time() + 10, 'wpab_generate_excerpt', [$attachment_id, $job_id]);
                }
                return;
            }

            $message = 'Excerpt failed after ' . OpenAIProvider::MAX_RETRIES . ' attempts: ' . $message;
        }

        update_post_meta($attachment_id, Meta::EXCERPT_STATUS, 'error');
        update_post_meta($attachment_id, Meta::EXCERPT_ERROR, $message);
        $this->fail_job($attachment_id, $job_id, $message);
        $this->logger->error('excerpt', $message, $attachment_id);
    }

    private function current_job(int $attachment_id, ?int $job_id = null): ?array
    {
        if (null !== $job_id && $job_id > 0) {
            $job = $this->jobs->get_by_id($job_id);
            if (null !== $job && (int) ($job['attachment_id'] ?? 0) === $attachment_id && 'excerpt' === ($job['operation'] ?? '')) {
                return $job;
            }
        }

        return $this->jobs->get_latest_for_attachment_operation($attachment_id, 'excerpt');
    }

    private function update_job_status(int $attachment_id, ?int $job_id, string $status, array $extra = []): void
    {
        $job = $this->current_job($attachment_id, $job_id);
        if (null === $job) {
            return;
        }

        $this->jobs->update((int) $job['id'], array_merge(['status' => $status], $extra));
    }

    private function complete_job(int $attachment_id, ?int $job_id): void
    {
        $this->update_job_status($attachment_id, $job_id, 'completed', ['completed_at' => current_time('mysql')]);
    }

    private function fail_job(int $attachment_id, ?int $job_id, string $message): void
    {
        $this->update_job_status($attachment_id, $job_id, 'failed', [
            'failed_at' => current_time('mysql'),
            'error_code' => 'excerpt_failed',
            'error_message' => $message,
        ]);
    }

    public function format_transcript(string $transcript): string
    {
        $formatter = new ParagraphFormatter();
        return $formatter->format($transcript);
    }

    private function responses_api(string $input, int $max_words, mixed $temperature = null): string|\WP_Error
    {
        $config = $this->settings->getProviderConfig('excerpt');
        $api_key = $config['api_key'];
        if ('' === $api_key) {
            return new \WP_Error(OpenAIProvider::ERROR_OPENAI_AUTH, 'OpenAI API key is missing.');
        }

        $model = $config['model'];
        $config['max_tokens'] = max(256, absint($this->settings->get('format_max_tokens', 32000)));

        if (is_numeric($temperature)) {
            $config['temperature'] = (float) $temperature;
        }

        $this->logger->info('excerpt', 'Sending excerpt request to text generation provider.', null, [
            'model' => $model,
            'max_words' => $max_words,
            'max_tokens' => $config['max_tokens'],
        ]);

        $provider = \AdrielPartners\WpAudioBuddy\Integrations\ProviderRegistry::getTextGenerationProvider($config['provider']);
        if ($provider === null) {
            return new \WP_Error('PROVIDER_UNAVAILABLE', 'No text generation provider available for: ' . ($config['provider'] ?? 'none'));
        }

        $response = $provider->generate($input, $config);
        if (is_wp_error($response) && isset($config['temperature'])) {
            // Retry without temperature if the model doesn't support it.
            unset($config['temperature']);
            $response = $provider->generate($input, $config);
        }

        if (is_wp_error($response)) {
            $this->logger->error('excerpt', 'Excerpt request failed: ' . $response->get_error_message(), null, ['model' => $model]);
        } else {
            $this->logger->info('excerpt', 'Excerpt request succeeded.', null, [
                'model' => $model,
                'characters' => function_exists('mb_strlen') ? mb_strlen($response) : strlen($response),
            ]);
        }

        return $response;
    }
}
