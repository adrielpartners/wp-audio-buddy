<?php

declare(strict_types=1);

namespace AdrielPartners\WpAudioBuddy\Services;

use AdrielPartners\WpAudioBuddy\Controllers\SettingsController;
use AdrielPartners\WpAudioBuddy\Data\GeneratedOutputRepository;
use AdrielPartners\WpAudioBuddy\Data\JobRepository;
use AdrielPartners\WpAudioBuddy\Data\LoggerRepository;
use AdrielPartners\WpAudioBuddy\Data\Meta;
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
        update_post_meta($attachment_id, Meta::EXCERPT_MODEL, $this->settings->get('excerpt_model'));
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
                'model' => $this->settings->get('excerpt_model'),
                'max_words' => $max_words,
            ]),
        ]);

        $this->complete_job($attachment_id, $job_id);
        $this->logger->info('excerpt', 'Excerpt generated successfully.', $attachment_id, ['prompt_type' => $prompt_type]);
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
        $response = $this->responses_api(
            "Format this transcript into readable paragraphs while preserving meaning and wording. Output plain text only.\n\n" . $transcript,
            1500,
            $this->settings->get('excerpt_temperature', null)
        );

        return is_wp_error($response) ? $transcript : $response;
    }

    private function responses_api(string $input, int $max_words, mixed $temperature = null): string|\WP_Error
    {
        $api_key = (string) $this->settings->get('api_key', '');
        if ('' === $api_key) {
            return new \WP_Error(OpenAIProvider::ERROR_OPENAI_AUTH, 'OpenAI API key is missing.');
        }

        $model = (string) $this->settings->get('excerpt_model', 'gpt-5-mini');
        $payload = [
            'model' => $model,
            'instructions' => 'Output plain text only. Maximum ' . $max_words . ' words.',
            'input' => $input,
        ];

        if (is_numeric($temperature)) {
            $payload['temperature'] = (float) $temperature;
        }

        $this->logger->info('excerpt', 'Sending excerpt request to OpenAI Responses API.', null, [
            'model' => $model,
            'temperature_included' => isset($payload['temperature']),
            'max_words' => $max_words,
        ]);

        $response = $this->openai->generate_text($api_key, $model, $payload);
        if (is_wp_error($response) && isset($payload['temperature']) && $this->is_temperature_unsupported_error($response)) {
            $this->logger->info('excerpt', 'Retrying excerpt request without temperature.', null, ['model' => $model]);
            unset($payload['temperature']);
            $response = $this->openai->generate_text($api_key, $model, $payload);
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

    private function is_temperature_unsupported_error(\WP_Error $error): bool
    {
        $message = strtolower($error->get_error_message());

        return str_contains($message, 'temperature')
            && (str_contains($message, 'unsupported parameter') || str_contains($message, 'not supported'));
    }
}
