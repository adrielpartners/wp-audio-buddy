<?php

declare(strict_types=1);

namespace AdrielPartners\WpAudioBuddy\Services;

use AdrielPartners\WpAudioBuddy\Controllers\SettingsController;
use AdrielPartners\WpAudioBuddy\Data\GeneratedOutputRepository;
use AdrielPartners\WpAudioBuddy\Data\LoggerRepository;
use AdrielPartners\WpAudioBuddy\Data\Meta;
use AdrielPartners\WpAudioBuddy\Integrations\OpenAIClient;

if (! defined('ABSPATH')) {
    exit;
}

final class ExcerptService
{
    public function __construct(
        private SettingsController $settings,
        private LoggerRepository $logger,
        private OpenAIClient $openai,
        private GeneratedOutputRepository $outputs
    ) {
    }

    public function handle(int $attachment_id): void
    {
        $transcript = (string) get_post_meta($attachment_id, Meta::TRANSCRIPT, true);
        if ('' === trim($transcript)) {
            $this->logger->info('excerpt', 'Skipped excerpt generation: transcript missing.', $attachment_id);
            return;
        }

        if ('done' === Meta::excerpt_status($attachment_id) && '' !== trim((string) get_post_meta($attachment_id, Meta::EXCERPT, true))) {
            $this->logger->info('excerpt', 'Skipped excerpt generation: already complete.', $attachment_id);
            return;
        }

        update_post_meta($attachment_id, Meta::EXCERPT_STATUS, 'running');

        $prompt_type = (string) $this->settings->get('excerpt_type', 'informative');
        $max_words = (int) $this->settings->get('excerpt_max_words', 100);
        $temperature = $this->settings->get('excerpt_temperature', null);
        $template = (string) $this->settings->get('excerpt_prompt_text', SettingsController::prompt_templates()[$prompt_type] ?? '');

        $prompt = str_replace(['{{MAX_WORDS}}', '{{TRANSCRIPT}}'], [(string) $max_words, $transcript], $template);
        $response = $this->responses_api($prompt, $max_words, $temperature);

        if (is_wp_error($response)) {
            $this->handle_excerpt_error($attachment_id, $response, $prompt_type, $template);
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
            'output_type' => 'excerpt',
            'prompt_type' => $prompt_type,
            'output_text' => $response,
            'metadata_json' => wp_json_encode([
                'model' => $this->settings->get('excerpt_model'),
                'max_words' => $max_words,
            ]),
        ]);

        $this->logger->info('excerpt', 'Excerpt generated successfully.', $attachment_id, ['prompt_type' => $prompt_type]);
    }

    /**
     * Handle an excerpt API error with bounded retry for transient failures.
     */
    private function handle_excerpt_error(int $attachment_id, \WP_Error $error, string $prompt_type, string $template): void
    {
        $message = $error->get_error_message();

        if (OpenAIClient::is_transient_error($error)) {
            // For excerpt generation, retry up to 2 times via Action Scheduler re-enqueue.
            $count_key = 'wpab_excerpt_retry_count_' . $attachment_id;
            $attempts = (int) get_transient($count_key);

            if ($attempts < OpenAIClient::MAX_RETRIES) {
                set_transient($count_key, $attempts + 1, HOUR_IN_SECONDS);
                update_post_meta($attachment_id, Meta::EXCERPT_STATUS, 'queued');
                $this->logger->info('excerpt_retry', 'Retrying excerpt after transient error.', $attachment_id, [
                    'attempt' => $attempts + 1,
                    'max' => OpenAIClient::MAX_RETRIES,
                    'error' => $message,
                ]);
                // Re-enqueue via the same hook.
                if (function_exists('as_enqueue_async_action')) {
                    as_enqueue_async_action('wpab_generate_excerpt', [$attachment_id], 'wp-audio-buddy');
                }
                return;
            }

            $message = 'Excerpt failed after ' . OpenAIClient::MAX_RETRIES . ' attempts: ' . $message;
            delete_transient($count_key);
        }

        update_post_meta($attachment_id, Meta::EXCERPT_STATUS, 'error');
        update_post_meta($attachment_id, Meta::EXCERPT_ERROR, $message);
        $this->logger->error('excerpt', $message, $attachment_id);
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
            return new \WP_Error(OpenAIClient::ERROR_OPENAI_AUTH, 'OpenAI API key is missing.');
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