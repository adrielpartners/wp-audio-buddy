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
        $endpoint = self::normalize_endpoint((string) ($config['endpoint'] ?? 'https://openrouter.ai/api'));

        if ('' === $api_key) {
            return new \WP_Error(OpenAIProvider::ERROR_OPENAI_AUTH, 'OpenRouter API key is missing.');
        }

        if (! file_exists($file_path)) {
            return new \WP_Error(OpenAIProvider::ERROR_AUDIO_NOT_FOUND, 'Audio file not found.');
        }

        if (! is_readable($file_path)) {
            return new \WP_Error(OpenAIProvider::ERROR_AUDIO_UNREADABLE, 'Audio file is not readable.');
        }

        if (! self::has_memory_for_base64_payload($file_path)) {
            return new \WP_Error(OpenAIProvider::ERROR_OPENAI_FILE_TOO_LARGE, 'Audio file is too large to prepare safely for OpenRouter transcription on this server.');
        }

        $url = $endpoint . '/v1/audio/transcriptions';
        $site_url = function_exists('get_site_url') ? get_site_url() : '';
        $audio = file_get_contents($file_path);
        if (false === $audio) {
            return new \WP_Error(OpenAIProvider::ERROR_AUDIO_UNREADABLE, 'Audio file could not be read.');
        }

        $data = [
            'input_audio' => [
                'data' => base64_encode($audio),
                'format' => self::audio_format($file_path, $mime),
            ],
            'model' => $model,
        ];

        $response = wp_safe_remote_post($url, [
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
                'HTTP-Referer' => $site_url,
                'X-Title' => 'WP Audio Buddy',
            ],
            'body' => wp_json_encode($data),
            'timeout' => 300,
        ]);

        if (is_wp_error($response)) {
            return new \WP_Error(OpenAIProvider::ERROR_OPENAI_NETWORK, $response->get_error_message());
        }

        $http_code = (int) wp_remote_retrieve_response_code($response);
        $raw = wp_remote_retrieve_body($response);

        if ($http_code >= 400) {
            $error = self::normalize_http_error($http_code, $raw);
            return new \WP_Error($error['code'], $error['message']);
        }

        $body = json_decode((string) $raw, true);
        if (empty($body['text'])) {
            return new \WP_Error(OpenAIProvider::ERROR_OPENAI_EMPTY, 'No transcript text was returned.');
        }

        return ['text' => $body['text'], 'model' => $model];
    }

    public function generate(string $prompt, array $config): string|\WP_Error
    {
        $api_key = (string) ($config['api_key'] ?? '');
        $model = (string) ($config['model'] ?? 'openai/gpt-4o-mini');
        $endpoint = self::normalize_endpoint((string) ($config['endpoint'] ?? 'https://openrouter.ai/api'));
        $temperature = $config['temperature'] ?? null;
        $site_url = function_exists('get_site_url') ? get_site_url() : '';

        if ('' === $api_key) {
            return new \WP_Error(OpenAIProvider::ERROR_OPENAI_AUTH, 'OpenRouter API key is missing.');
        }

        $url = $endpoint . '/v1/chat/completions';

        $data = [
            'model' => $model,
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
            'max_tokens' => (int) ($config['max_tokens'] ?? 32000),
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
            return new \WP_Error(OpenAIProvider::ERROR_OPENAI_NETWORK, $response->get_error_message());
        }

        $http_code = (int) wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($http_code >= 400) {
            $error = self::normalize_http_error($http_code, $body);
            return new \WP_Error($error['code'], $error['message']);
        }

        $json = json_decode($body, true);
        $text = $json['choices'][0]['message']['content'] ?? '';

        if ('' === trim($text)) {
            return new \WP_Error(OpenAIProvider::ERROR_OPENAI_EMPTY, 'No content was returned by the model.');
        }

        return trim($text);
    }

    private static function normalize_endpoint(string $endpoint): string
    {
        $endpoint = rtrim($endpoint ?: 'https://openrouter.ai/api', '/');
        return preg_replace('#/v1$#', '', $endpoint) ?: 'https://openrouter.ai/api';
    }

    private static function audio_format(string $file_path, string $mime): string
    {
        $extension = strtolower((string) pathinfo($file_path, PATHINFO_EXTENSION));
        if (in_array($extension, ['wav', 'mp3', 'flac', 'm4a', 'ogg', 'webm', 'aac'], true)) {
            return $extension;
        }

        return match (strtolower($mime)) {
            'audio/wav', 'audio/x-wav' => 'wav',
            'audio/mpeg', 'audio/mp3' => 'mp3',
            'audio/flac', 'audio/x-flac' => 'flac',
            'audio/mp4', 'audio/x-m4a' => 'm4a',
            'audio/ogg', 'application/ogg' => 'ogg',
            'audio/webm', 'video/webm' => 'webm',
            'audio/aac' => 'aac',
            default => $extension ?: 'mp3',
        };
    }

    private static function has_memory_for_base64_payload(string $file_path): bool
    {
        $limit = self::memory_limit_bytes();
        if ($limit <= 0) {
            return true;
        }

        $size = filesize($file_path);
        if (false === $size) {
            return true;
        }

        $estimated = (int) ceil((float) $size * 2.5);
        return memory_get_usage(true) + $estimated < (int) ($limit * 0.85);
    }

    private static function memory_limit_bytes(): int
    {
        $raw = ini_get('memory_limit');
        if (false === $raw || '' === $raw || '-1' === $raw) {
            return -1;
        }

        $raw = trim($raw);
        $unit = strtolower(substr($raw, -1));
        $value = (int) $raw;

        return match ($unit) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value,
        };
    }

    private static function normalize_http_error(int $http_code, string $raw_body): array
    {
        $body = json_decode($raw_body, true);
        $message = $body['error']['message'] ?? ($body['error'] ?? 'OpenRouter API error.');

        $code = match (true) {
            $http_code === 401 => OpenAIProvider::ERROR_OPENAI_AUTH,
            $http_code === 429 => OpenAIProvider::ERROR_OPENAI_RATE_LIMIT,
            $http_code === 413 => OpenAIProvider::ERROR_OPENAI_FILE_TOO_LARGE,
            $http_code >= 500 => OpenAIProvider::ERROR_OPENAI_SERVER,
            $http_code >= 400 => OpenAIProvider::ERROR_OPENAI_INVALID,
            default => OpenAIProvider::ERROR_OPENAI_NETWORK,
        };

        return ['code' => $code, 'message' => is_string($message) ? $message : 'OpenRouter API error.'];
    }
}
