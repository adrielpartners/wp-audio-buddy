<?php

declare(strict_types=1);

namespace AdrielPartners\WpAudioBuddy\Integrations\Providers;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Anthropic provider — text generation only via native Messages API.
 *
 * Not OpenAI-compatible. Uses x-api-key header and different request/response format.
 */
final class AnthropicProvider implements TextGenerationProviderInterface
{
    public function generate(string $prompt, array $config): string|\WP_Error
    {
        $api_key = (string) ($config['api_key'] ?? '');
        $model = (string) ($config['model'] ?? 'claude-3-haiku-20240307');
        $endpoint = rtrim((string) ($config['endpoint'] ?? 'https://api.anthropic.com'), '/');
        $max_tokens = (int) ($config['max_tokens'] ?? 32000);

        if ('' === $api_key) {
            return new \WP_Error('AUTH_FAILED', 'Anthropic API key is missing.');
        }

        $url = $endpoint . '/v1/messages';

        $data = [
            'model' => $model,
            'max_tokens' => $max_tokens,
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
        ];

        $args = [
            'headers' => [
                'Content-Type' => 'application/json',
                'x-api-key' => $api_key,
                'anthropic-version' => '2023-06-01',
            ],
            'body' => wp_json_encode($data),
            'timeout' => 120,
        ];

        $response = wp_safe_remote_post($url, $args);

        if (is_wp_error($response)) {
            return new \WP_Error('NETWORK_ERROR', $response->get_error_message());
        }

        $http_code = (int) wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($http_code >= 400) {
            $json_body = json_decode($body, true);
            $message = $json_body['error']['message'] ?? ($json_body['error'] ?? 'Anthropic API error.');
            $code = match (true) {
                $http_code === 401 => 'AUTH_FAILED',
                $http_code === 429 => 'RATE_LIMITED',
                $http_code >= 500 => 'SERVER_ERROR',
                default => 'INVALID_REQUEST',
            };
            return new \WP_Error($code, $message);
        }

        $json = json_decode($body, true);
        $text = '';

        // Anthropic response format: content[0].text
        foreach ($json['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= $block['text'];
            }
        }

        if ('' === trim($text)) {
            return new \WP_Error('EMPTY_RESPONSE', 'No content was returned by the model.');
        }

        return trim($text);
    }
}