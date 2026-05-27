<?php

declare(strict_types=1);

namespace AdrielPartners\WpAudioBuddy\Integrations\Providers;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Google Gemini provider — text generation only via native Gemini API.
 *
 * API key passed as query parameter. Different request/response format.
 */
final class GeminiProvider implements TextGenerationProviderInterface
{
    public function generate(string $prompt, array $config): string|\WP_Error
    {
        $api_key = (string) ($config['api_key'] ?? '');
        $model = (string) ($config['model'] ?? 'gemini-2.0-flash-001');

        if ('' === $api_key) {
            return new \WP_Error('AUTH_FAILED', 'Gemini API key is missing.');
        }

        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent?key=' . rawurlencode($api_key);

        $data = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt],
                    ],
                ],
            ],
            'generationConfig' => [
                'maxOutputTokens' => 1500,
            ],
        ];

        $args = [
            'headers' => [
                'Content-Type' => 'application/json',
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
            $message = $json_body['error']['message'] ?? ($json_body['error'] ?? 'Gemini API error.');
            $code = match (true) {
                $http_code === 401 || $http_code === 403 => 'AUTH_FAILED',
                $http_code === 429 => 'RATE_LIMITED',
                $http_code >= 500 => 'SERVER_ERROR',
                default => 'INVALID_REQUEST',
            };
            return new \WP_Error($code, $message);
        }

        $json = json_decode($body, true);
        $text = $json['candidates'][0]['content']['parts'][0]['text'] ?? '';

        if ('' === trim($text)) {
            return new \WP_Error('EMPTY_RESPONSE', 'No content was returned by the model.');
        }

        return trim($text);
    }
}