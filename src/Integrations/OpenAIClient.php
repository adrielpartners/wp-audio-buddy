<?php

declare(strict_types=1);

namespace AdrielPartners\WpAudioBuddy\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Unified client for OpenAI API communication.
 *
 * Handles both audio transcription (multipart upload via cURL) and
 * text generation (JSON via wp_remote_post). Normalizes all errors
 * into consistent error codes with user-safe messages.
 */
final class OpenAIClient
{
    public const MAX_RETRIES = 2;

    // Error codes
    public const ERROR_AUDIO_NOT_FOUND = 'AUDIO_NOT_FOUND';
    public const ERROR_AUDIO_UNREADABLE = 'AUDIO_UNREADABLE';
    public const ERROR_CURL_MISSING = 'CURL_REQUIRED';
    public const ERROR_OPENAI_AUTH = 'OPENAI_AUTH_FAILED';
    public const ERROR_OPENAI_RATE_LIMIT = 'OPENAI_RATE_LIMITED';
    public const ERROR_OPENAI_SERVER = 'OPENAI_SERVER_ERROR';
    public const ERROR_OPENAI_NETWORK = 'OPENAI_REQUEST_FAILED';
    public const ERROR_OPENAI_INVALID_REQUEST = 'OPENAI_INVALID_REQUEST';
    public const ERROR_OPENAI_FILE_TOO_LARGE = 'OPENAI_FILE_TOO_LARGE';
    public const ERROR_OPENAI_EMPTY = 'OPENAI_EMPTY_RESPONSE';

    private const TRANSCRIPTION_URL = 'https://api.openai.com/v1/audio/transcriptions';
    private const RESPONSES_URL = 'https://api.openai.com/v1/responses';

    /**
     * Transcribe an audio file via OpenAI's audio/transcriptions endpoint.
     *
     * @param string $api_key  OpenAI API key.
     * @param string $model    Model name (e.g. gpt-4o-mini-transcribe).
     * @param string $file_path Absolute path to the audio file.
     * @param string $mime     MIME type of the audio file.
     *
     * @return array{text: string}|WP_Error Array with 'text' key on success, WP_Error on failure.
     */
    public function transcribe(string $api_key, string $model, string $file_path, string $mime): array|\WP_Error
    {
        if (! file_exists($file_path)) {
            return new \WP_Error(self::ERROR_AUDIO_NOT_FOUND, 'Audio file not found at the expected path.');
        }

        if (! is_readable($file_path)) {
            return new \WP_Error(self::ERROR_AUDIO_UNREADABLE, 'Audio file exists but cannot be read.');
        }

        if (! function_exists('curl_init')) {
            return new \WP_Error(self::ERROR_CURL_MISSING, 'cURL is required for audio transcription requests.');
        }

        $ch = \curl_init(self::TRANSCRIPTION_URL);
        $postfields = [
            'file' => \curl_file_create($file_path, $mime ?: 'application/octet-stream', basename($file_path)),
            'model' => $model,
            'response_format' => 'json',
        ];

        \curl_setopt_array($ch, [
            \CURLOPT_POST => true,
            \CURLOPT_POSTFIELDS => $postfields,
            \CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $api_key],
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_TIMEOUT => 300,
            \CURLOPT_CONNECTTIMEOUT => 30,
        ]);

        $raw = \curl_exec($ch);
        $curl_errno = \curl_errno($ch);
        $curl_error = \curl_error($ch);
        $http_code = (int) \curl_getinfo($ch, \CURLINFO_RESPONSE_CODE);
        \curl_close($ch);

        if (false === $raw) {
            return $this->normalize_curl_error($curl_errno, $curl_error);
        }

        return $this->parse_transcription_response($raw, $http_code, $model);
    }

    /**
     * Generate text via OpenAI's Responses API.
     *
     * @param string $api_key OpenAI API key.
     * @param string $model   Model name.
     * @param array  $payload Request payload (input, instructions, temperature, etc.).
     *
     * @return string|WP_Error The generated text on success, WP_Error on failure.
     */
    public function generate_text(string $api_key, string $model, array $payload): string|\WP_Error
    {
        if ('' === $api_key) {
            return new \WP_Error(self::ERROR_OPENAI_AUTH, 'OpenAI API key is missing.');
        }

        $res = \wp_remote_post(self::RESPONSES_URL, [
            'timeout' => 120,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
            ],
            'body' => \wp_json_encode($payload),
        ]);

        if (is_wp_error($res)) {
            return $this->normalize_wp_error($res);
        }

        $status = \wp_remote_retrieve_response_code($res);
        $body = \json_decode((string) \wp_remote_retrieve_body($res), true);

        if ($status >= 400) {
            return $this->normalize_http_error($status, $body);
        }

        $text = $this->extract_output_text($body);
        if ('' === trim($text)) {
            return new \WP_Error(self::ERROR_OPENAI_EMPTY, 'No text was returned by the AI model.');
        }

        return $text;
    }

    /**
     * Check whether a WP_Error represents a transient (retryable) failure.
     */
    public static function is_transient_error(\WP_Error $error): bool
    {
        $transient_codes = [
            self::ERROR_OPENAI_RATE_LIMIT,
            self::ERROR_OPENAI_SERVER,
            self::ERROR_OPENAI_NETWORK,
        ];

        return in_array($error->get_error_code(), $transient_codes, true);
    }

    /**
     * Normalize cURL errors into plugin error codes.
     */
    private function normalize_curl_error(int $errno, string $error): \WP_Error
    {
        $transient_errnos = [
            \CURLE_OPERATION_TIMEDOUT,
            \CURLE_COULDNT_CONNECT,
            \CURLE_COULDNT_RESOLVE_HOST,
            \CURLE_GOT_NOTHING,
        ];

        if (in_array($errno, $transient_errnos, true)) {
            return new \WP_Error(self::ERROR_OPENAI_NETWORK, $error ?: 'Network request to OpenAI failed.');
        }

        return new \WP_Error(self::ERROR_OPENAI_REQUEST_FAILED, $error ?: 'Transcription request failed.');
    }

    /**
     * Normalize wp_remote_post errors into plugin error codes.
     */
    private function normalize_wp_error(\WP_Error $error): \WP_Error
    {
        $msg = $error->get_error_message();

        // Detect transient network errors
        if (str_contains($msg, 'timeout') || str_contains($msg, 'could not') || str_contains($msg, 'reset')) {
            return new \WP_Error(self::ERROR_OPENAI_NETWORK, 'Network request to OpenAI failed.');
        }

        return new \WP_Error(self::ERROR_OPENAI_REQUEST_FAILED, $msg);
    }

    /**
     * Normalize HTTP-level errors from OpenAI.
     */
    private function normalize_http_error(int $status, ?array $body): \WP_Error
    {
        $message = (string) ($body['error']['message'] ?? 'OpenAI request failed.');

        return match (true) {
            401 === $status => new \WP_Error(self::ERROR_OPENAI_AUTH, 'Invalid OpenAI API key.'),
            429 === $status => new \WP_Error(self::ERROR_OPENAI_RATE_LIMIT, 'OpenAI rate limit reached. Please wait and try again.'),
            $status >= 500 => new \WP_Error(self::ERROR_OPENAI_SERVER, 'OpenAI server error. Please try again later.'),
            413 === $status => new \WP_Error(self::ERROR_OPENAI_FILE_TOO_LARGE, 'Audio file exceeds OpenAI size limits.'),
            default => new \WP_Error(self::ERROR_OPENAI_INVALID_REQUEST, $message),
        };
    }

    /**
     * Parse and validate the transcription API response.
     *
     * @return array{text: string}|WP_Error
     */
    private function parse_transcription_response(string $raw, int $http_code, string $model): array|\WP_Error
    {
        $body = \json_decode($raw, true);

        if ($http_code >= 400) {
            return $this->normalize_http_error($http_code, $body);
        }

        if (empty($body['text'])) {
            return new \WP_Error(self::ERROR_OPENAI_EMPTY, 'No transcript text was returned by OpenAI.');
        }

        return ['text' => $body['text']];
    }

    /**
     * Extract output text from different response formats.
     */
    private function extract_output_text(array $body): string
    {
        $text = (string) ($body['output_text'] ?? '');
        if ('' !== trim($text)) {
            return $text;
        }

        $chunks = [];
        foreach ((array) ($body['output'] ?? []) as $item) {
            foreach ((array) ($item['content'] ?? []) as $content) {
                if (isset($content['text'])) {
                    $chunks[] = (string) $content['text'];
                }
            }
        }

        return trim(implode("\n", array_filter($chunks, static fn ($value): bool => '' !== trim((string) $value))));
    }
}