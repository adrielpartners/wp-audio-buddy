<?php

declare(strict_types=1);

namespace AdrielPartners\WpAudioBuddy\Integrations\Providers;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * OpenAI provider — implements both transcription and text generation.
 *
 * Also serves as the base pattern for OpenAI-compatible providers (Groq, OpenRouter, DeepSeek).
 */
final class OpenAIProvider implements TranscriptionProviderInterface, TextGenerationProviderInterface
{
    public const MAX_RETRIES = 2;

    public const ERROR_AUDIO_NOT_FOUND = 'AUDIO_NOT_FOUND';
    public const ERROR_AUDIO_UNREADABLE = 'AUDIO_UNREADABLE';
    public const ERROR_OPENAI_AUTH = 'OPENAI_AUTH_FAILED';
    public const ERROR_OPENAI_RATE_LIMIT = 'OPENAI_RATE_LIMITED';
    public const ERROR_OPENAI_SERVER = 'OPENAI_SERVER_ERROR';
    public const ERROR_OPENAI_NETWORK = 'OPENAI_NETWORK';
    public const ERROR_OPENAI_INVALID = 'OPENAI_INVALID_REQUEST';
    public const ERROR_OPENAI_FILE_TOO_LARGE = 'OPENAI_FILE_TOO_LARGE';
    public const ERROR_OPENAI_EMPTY = 'OPENAI_EMPTY_RESPONSE';
    public const ERROR_CURL_MISSING = 'CURL_REQUIRED';

    private const RETRYABLE_HTTP = [429, 500, 502, 503, 504];

    public function transcribe(string $file_path, string $mime, array $config): array|\WP_Error
    {
        $api_key = (string) ($config['api_key'] ?? '');
        $model = (string) ($config['model'] ?? 'gpt-4o-mini-transcribe');
        $endpoint = rtrim((string) ($config['endpoint'] ?? 'https://api.openai.com'), '/');
        // Normalize: strip trailing /v1 so the code below can safely append it.
        $endpoint = preg_replace('#/v1$#', '', $endpoint);

        if ('' === $api_key) {
            return new \WP_Error(self::ERROR_OPENAI_AUTH, 'OpenAI API key is missing.');
        }

        if (! file_exists($file_path)) {
            return new \WP_Error(self::ERROR_AUDIO_NOT_FOUND, 'Audio file not found at: ' . $file_path);
        }

        if (! is_readable($file_path)) {
            return new \WP_Error(self::ERROR_AUDIO_UNREADABLE, 'Audio file is not readable.');
        }

        if (! function_exists('curl_init')) {
            return new \WP_Error(self::ERROR_CURL_MISSING, 'cURL is required for transcription.');
        }

        $url = $endpoint . '/v1/audio/transcriptions';
        $file_name = basename($file_path);

        // Prepare multipart form data via CURLFile.
        $cfile = new \CURLFile($file_path, $mime, $file_name);
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
                // Pass through additional headers from provider config (e.g. X-Title for OpenRouter)
            ],
        ]);

        // Apply any additional headers from config (e.g. OpenRouter X-Title).
        $extra_headers = $config['headers'] ?? [];
        if (is_array($extra_headers) && ! empty($extra_headers)) {
            $current_headers = curl_getinfo($ch, CURLOPT_HTTPHEADER) ?: [
                'Authorization: Bearer ' . $api_key,
            ];
            foreach ($extra_headers as $key => $value) {
                $current_headers[] = "$key: $value";
            }
            curl_setopt($ch, CURLOPT_HTTPHEADER, $current_headers);
        }

        $raw = curl_exec($ch);
        $curl_err = curl_error($ch);
        $http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (false === $raw) {
            return new \WP_Error(self::ERROR_OPENAI_NETWORK, $curl_err ?: 'Transcription request failed.');
        }

        if ($http_code >= 400) {
            $error = self::normalize_http_error($http_code, $raw);
            return new \WP_Error($error['code'], $error['message']);
        }

        $body = json_decode((string) $raw, true);
        if (empty($body['text'])) {
            return new \WP_Error(self::ERROR_OPENAI_EMPTY, 'No transcript text was returned.');
        }

        return ['text' => $body['text'], 'model' => $model];
    }

    public function generate(string $prompt, array $config): string|\WP_Error
    {
        $api_key = (string) ($config['api_key'] ?? '');
        $model = (string) ($config['model'] ?? 'gpt-4o-mini');
        $endpoint = rtrim((string) ($config['endpoint'] ?? 'https://api.openai.com'), '/');
        // Normalize: strip trailing /v1 so the code below can safely append it.
        $endpoint = preg_replace('#/v1$#', '', $endpoint);
        $temperature = $config['temperature'] ?? null;

        if ('' === $api_key) {
            return new \WP_Error(self::ERROR_OPENAI_AUTH, 'OpenAI API key is missing.');
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
            ],
            'body' => wp_json_encode($data),
            'timeout' => 120,
        ];

        $response = wp_safe_remote_post($url, $args);

        if (is_wp_error($response)) {
            return new \WP_Error(self::ERROR_OPENAI_NETWORK, $response->get_error_message());
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
            return new \WP_Error(self::ERROR_OPENAI_EMPTY, 'No content was returned by the model.');
        }

        return trim($text);
    }

    /**
     * Determine whether an error is transient (retryable).
     */
    public static function is_transient_error(\WP_Error $error): bool
    {
        $code = $error->get_error_code();

        return in_array($code, [
            self::ERROR_OPENAI_RATE_LIMIT,
            self::ERROR_OPENAI_SERVER,
            self::ERROR_OPENAI_NETWORK,
        ], true);
    }

    /**
     * Normalize HTTP error codes to plugin error codes.
     */
    private static function normalize_http_error(int $http_code, string $raw_body): array
    {
        $body = json_decode($raw_body, true);
        $message = $body['error']['message'] ?? ($body['error'] ?? 'Unknown API error.');

        $code = match (true) {
            $http_code === 401 => self::ERROR_OPENAI_AUTH,
            $http_code === 429 => self::ERROR_OPENAI_RATE_LIMIT,
            $http_code === 413 => self::ERROR_OPENAI_FILE_TOO_LARGE,
            $http_code >= 500 => self::ERROR_OPENAI_SERVER,
            $http_code >= 400 => self::ERROR_OPENAI_INVALID,
            default => self::ERROR_OPENAI_NETWORK,
        };

        return ['code' => $code, 'message' => $message];
    }
}