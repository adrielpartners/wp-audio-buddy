<?php

if (! defined('ABSPATH')) {
    exit;
}

final class WPAB_ExcerptService
{
    public function __construct(private WPAB_Settings $settings, private WPAB_Logger $logger)
    {
    }

    public function handle(int $attachment_id): void
    {
        $transcript = (string) get_post_meta($attachment_id, WPAB_Meta::TRANSCRIPT, true);
        if ('' === trim($transcript)) {
            $this->logger->info('excerpt', 'Skipped excerpt generation: transcript missing.', $attachment_id);
            return;
        }

        if ('done' === WPAB_Meta::excerpt_status($attachment_id) && '' !== trim((string) get_post_meta($attachment_id, WPAB_Meta::EXCERPT, true))) {
            $this->logger->info('excerpt', 'Skipped excerpt generation: already complete.', $attachment_id);
            return;
        }

        update_post_meta($attachment_id, WPAB_Meta::EXCERPT_STATUS, 'running');

        $prompt_type = (string) $this->settings->get('excerpt_type', 'informative');
        $max_words = (int) $this->settings->get('excerpt_max_words', 100);
        $temperature = $this->settings->get('excerpt_temperature', null);
        $template = (string) $this->settings->get('excerpt_prompt_text', WPAB_Settings::prompt_templates()[$prompt_type] ?? '');

        $prompt = str_replace(['{{MAX_WORDS}}', '{{TRANSCRIPT}}'], [(string) $max_words, $transcript], $template);
        $response = $this->responses_api($prompt, $max_words, $temperature);

        if (is_wp_error($response)) {
            update_post_meta($attachment_id, WPAB_Meta::EXCERPT_STATUS, 'error');
            update_post_meta($attachment_id, WPAB_Meta::EXCERPT_ERROR, $response->get_error_message());
            $this->logger->error('excerpt', $response->get_error_message(), $attachment_id);
            return;
        }

        update_post_meta($attachment_id, WPAB_Meta::EXCERPT, $response);
        update_post_meta($attachment_id, WPAB_Meta::EXCERPT_ERROR, '');
        update_post_meta($attachment_id, WPAB_Meta::EXCERPT_STATUS, 'done');
        update_post_meta($attachment_id, WPAB_Meta::EXCERPT_MODEL, $this->settings->get('excerpt_model'));
        update_post_meta($attachment_id, WPAB_Meta::EXCERPT_PROMPT_TYPE, $prompt_type);
        update_post_meta($attachment_id, WPAB_Meta::EXCERPT_PROMPT_CUSTOM, $template);
        update_post_meta($attachment_id, WPAB_Meta::EXCERPT_UPDATED, current_time('mysql'));

        $this->logger->info('excerpt', 'Excerpt generated successfully.', $attachment_id, ['prompt_type' => $prompt_type]);
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

    private function responses_api(string $input, int $max_words, mixed $temperature = null): string|WP_Error
    {
        $api_key = (string) $this->settings->get('api_key', '');
        if ('' === $api_key) {
            return new WP_Error('wpab_missing_key', 'OpenAI API key is missing.');
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
            'temperature' => isset($payload['temperature']) ? round((float) $payload['temperature'], 2) : null,
            'max_words' => $max_words,
        ]);

        $response = $this->request_responses_api($api_key, $payload, $model);
        if (is_wp_error($response) && isset($payload['temperature']) && $this->is_temperature_unsupported_error($response)) {
            $this->logger->info('excerpt', 'Retrying excerpt request without temperature.', null, [
                'model' => $model,
            ]);
            unset($payload['temperature']);
            $response = $this->request_responses_api($api_key, $payload, $model);
        }

        return $response;
    }

    private function request_responses_api(string $api_key, array $payload, string $model): string|WP_Error
    {
        $res = wp_remote_post('https://api.openai.com/v1/responses', [
            'timeout' => 120,
            'headers' => ['Authorization' => 'Bearer ' . $api_key, 'Content-Type' => 'application/json'],
            'body' => wp_json_encode($payload),
        ]);

        if (is_wp_error($res)) {
            $this->logger->error('excerpt', 'Excerpt request failed: ' . $this->truncate_for_log($res->get_error_message()), null, ['model' => $model]);
            return $res;
        }

        $status = wp_remote_retrieve_response_code($res);
        $body = json_decode((string) wp_remote_retrieve_body($res), true);

        if ($status >= 400) {
            $message = (string) ($body['error']['message'] ?? 'OpenAI request failed.');
            $this->logger->error('excerpt', 'Excerpt request failed: ' . $this->truncate_for_log($message), null, ['model' => $model]);
            return new WP_Error('wpab_openai_error', $message);
        }

        $text = $this->extract_response_text(is_array($body) ? $body : []);
        if ('' === trim($text)) {
            $this->logger->error('excerpt', 'Excerpt request failed: empty text response.', null, ['model' => $model]);
            return new WP_Error('wpab_empty_response', 'No text returned from OpenAI.');
        }

        $clean_text = trim($text);
        $this->logger->info('excerpt', 'Excerpt request succeeded.', null, [
            'model' => $model,
            'characters' => function_exists('mb_strlen') ? mb_strlen($clean_text) : strlen($clean_text),
        ]);

        return $clean_text;
    }

    private function extract_response_text(array $body): string
    {
        $text = (string) ($body['output_text'] ?? '');
        if ('' !== trim($text)) {
            return $text;
        }

        $chunks = [];
        foreach ((array) ($body['output'] ?? []) as $item) {
            foreach ((array) ($item['content'] ?? []) as $content) {
                if ('output_text' === ($content['type'] ?? '') && isset($content['text'])) {
                    $chunks[] = (string) $content['text'];
                } elseif (isset($content['text'])) {
                    $chunks[] = (string) $content['text'];
                }
            }
        }

        return trim(implode("\n", array_filter($chunks, static fn ($value): bool => '' !== trim((string) $value))));
    }

    private function is_temperature_unsupported_error(WP_Error $error): bool
    {
        $message = strtolower($error->get_error_message());
        return str_contains($message, 'temperature')
            && (str_contains($message, 'unsupported parameter') || str_contains($message, 'not supported'));
    }

    private function truncate_for_log(string $message, int $limit = 200): string
    {
        $clean = trim($message);
        if ((function_exists('mb_strlen') ? mb_strlen($clean) : strlen($clean)) <= $limit) {
            return $clean;
        }

        if (function_exists('mb_substr')) {
            return mb_substr($clean, 0, max(0, $limit - 3)) . '...';
        }

        return substr($clean, 0, max(0, $limit - 3)) . '...';
    }

    private function extract_response_text(array $body): string
    {
        $text = (string) ($body['output_text'] ?? '');
        if ('' !== trim($text)) {
            return $text;
        }

        $chunks = [];
        foreach ((array) ($body['output'] ?? []) as $item) {
            foreach ((array) ($item['content'] ?? []) as $content) {
                if ('output_text' === ($content['type'] ?? '') && isset($content['text'])) {
                    $chunks[] = (string) $content['text'];
                } elseif (isset($content['text'])) {
                    $chunks[] = (string) $content['text'];
                }
            }
        }

        return trim(implode("\n", array_filter($chunks, static fn ($value): bool => '' !== trim((string) $value))));
    }

    private function is_temperature_unsupported_error(WP_Error $error): bool
    {
        $message = strtolower($error->get_error_message());
        return str_contains($message, 'unsupported parameter') && str_contains($message, 'temperature');
    }
}
