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
 * Adds required OpenRouter headers (HTTP-Referer, X-Title) to all requests.
 */
final class OpenRouterProvider implements TranscriptionProviderInterface, TextGenerationProviderInterface
{
    public function transcribe(string $file_path, string $mime, array $config): array|\WP_Error
    {
        $api_key = (string) ($config['api_key'] ?? '');
        $model = (string) ($config['model'] ?? 'openai/whisper-1');
        $endpoint = rtrim((string) ($config['endpoint'] ?? 'https://openrouter.ai/api'), '/');
        $endpoint = preg_replace('#/v1$#', '', $endpoint);

        if ('' === $api_key) {
            return new \WP_Error('AUTH_FAILED', 'OpenRouter API key is missing.');
        }

        if (! file_exists($file_path)) {
            return new \WP_Error('AUDIO_NOT_FOUND', 'Audio file not found.');
        }

        if (! function_exists('curl_init')) {
            return new \WP_Error('CURL_REQUIRED', 'cURL is required for transcription.');
        }

        $url = $endpoint . '/v1/audio/transcriptions';
        $site_url = function_exists('get_site_url') ? get_site_url() : '';

        $cfile = new \CURLFile($file_path, $mime, basename($file_path));
        $post_data = [
            'file' => $cfile,
            'model' => $model,
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $post_data,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $api_key,
                'X-Title: WP Audio Buddy',
                'HTTP-Referer: ' . $site_url,
            ],
        ]);

        $raw = curl_exec($ch);
        $curl_err = curl_error($ch);
        $http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (false === $raw) {
            return new \WP_Error('NETWORK_ERROR', $curl_err ?: 'Transcription request failed.');
        }

        if ($http_code >= 400) {
            $body = json_decode($raw, true);
            $message = $body['error']['message'] ?? ($body['error'] ?? 'Unknown API error.');
            $code = match (true) {
                $http_code === 401 => 'AUTH_FAILED',
                $http_code === 429 => 'RATE_LIMITED',
                $http_code >= 500 => 'SERVER_ERROR',
                default => 'INVALID_REQUEST',
            };
            return new \WP_Error($code, $message);
        }

        $body = json_decode((string) $raw, true);
        if (empty($body['text'])) {
            return new \WP_Error('EMPTY_RESPONSE', 'No transcript text was returned.');
        }

        return ['text' => $body['text'], 'model' => $model];
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