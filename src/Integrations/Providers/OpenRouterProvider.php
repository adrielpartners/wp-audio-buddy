<?php

declare(strict_types=1);

namespace AdrielPartners\WpAudioBuddy\Integrations\Providers;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * OpenRouter provider — supports both transcription and text generation
 * via OpenAI-compatible endpoints.
 *
 * Adds required OpenRouter headers (HTTP-Referer, X-Title).
 * For transcription, delegates to OpenAIProvider with OpenRouter's base URL.
 * For text generation, uses wp_safe_remote_post with OpenRouter headers.
 */
final class OpenRouterProvider implements TranscriptionProviderInterface, TextGenerationProviderInterface
{
    public function transcribe(string $file_path, string $mime, array $config): array|\WP_Error
    {
        // OpenRouter requires X-Title and HTTP-Referer on all API calls.
        $site_url = function_exists('get_site_url') ? get_site_url() : '';
        $config['endpoint'] = $config['endpoint'] ?? 'https://openrouter.ai/api';
        $config['model'] = $config['model'] ?? 'openai/whisper-1';
        $config['headers'] = [
            'X-Title' => 'WP Audio Buddy',
            'HTTP-Referer' => $site_url,
        ];

        $openai = new OpenAIProvider();
        return $openai->transcribe($file_path, $mime, $config);
    }

    public function generate(string $prompt, array $config): string|\WP_Error
    {
        $api_key = (string) ($config['api_key'] ?? '');
        $model = (string) ($config['model'] ?? 'openai/gpt-4o-mini');
        $endpoint = rtrim((string) ($config['endpoint'] ?? 'https://openrouter.ai/api'), '/');
        $temperature = $config['temperature'] ?? null;
        $site_url = function_exists('get_site_url') ? get_site_url() : '';

        if ('' === $api_key) {
            return new \WP_Error('AUTH_FAILED', 'OpenRouter API key is missing.');
        }

        $url = $endpoint . '/v1/chat/completions';

        $data = [
            'model' => $model,
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
            'max_tokens' => 1500,
        ];

        if ($temperature !== null) {
            $data['temperature'] = (float) $temperature;
        }

        $args = [
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
                'HTTP-Referer' => $site_url,
                'X-Title' => 'WP Audio Buddy',
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
            $message = $json_body['error']['message'] ?? ($json_body['error'] ?? 'OpenRouter API error.');
            $code = match (true) {
                $http_code === 401 => 'AUTH_FAILED',
                $http_code === 429 => 'RATE_LIMITED',
                $http_code >= 500 => 'SERVER_ERROR',
                default => 'INVALID_REQUEST',
            };
            return new \WP_Error($code, $message);
        }

        $json = json_decode($body, true);
        $text = $json['choices'][0]['message']['content'] ?? '';

        if ('' === trim($text)) {
            return new \WP_Error('EMPTY_RESPONSE', 'No content was returned by the model.');
        }

        return trim($text);
    }
}