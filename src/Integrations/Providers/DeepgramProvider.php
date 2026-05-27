<?php

declare(strict_types=1);

namespace AdrielPartners\WpAudioBuddy\Integrations\Providers;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Deepgram provider — transcription only via native Deepgram API.
 *
 * Uses Token-based authentication and a different response format.
 */
final class DeepgramProvider implements TranscriptionProviderInterface
{
    public function transcribe(string $file_path, string $mime, array $config): array|\WP_Error
    {
        $api_key = (string) ($config['api_key'] ?? '');
        $model = (string) ($config['model'] ?? 'nova-2');
        $endpoint = rtrim((string) ($config['endpoint'] ?? 'https://api.deepgram.com'), '/');

        if ('' === $api_key) {
            return new \WP_Error('AUTH_FAILED', 'Deepgram API key is missing.');
        }

        if (! file_exists($file_path)) {
            return new \WP_Error('AUDIO_NOT_FOUND', 'Audio file not found.');
        }

        $url = $endpoint . '/v1/listen?model=' . rawurlencode($model) . '&punctuate=true';

        $args = [
            'headers' => [
                'Authorization' => 'Token ' . $api_key,
                'Content-Type' => $mime,
            ],
            'body' => file_get_contents($file_path),
            'timeout' => 300,
        ];

        $response = wp_safe_remote_post($url, $args);

        if (is_wp_error($response)) {
            return new \WP_Error('NETWORK_ERROR', $response->get_error_message());
        }

        $http_code = (int) wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($http_code >= 400) {
            $json_body = json_decode($body, true);
            $message = $json_body['err_msg'] ?? ($json_body['err_message'] ?? 'Deepgram API error.');
            $code = match (true) {
                $http_code === 401 || $http_code === 403 => 'AUTH_FAILED',
                $http_code === 429 => 'RATE_LIMITED',
                $http_code >= 500 => 'SERVER_ERROR',
                default => 'INVALID_REQUEST',
            };
            return new \WP_Error($code, $message);
        }

        $json = json_decode($body, true);
        $text = $json['results']['channels'][0]['alternatives'][0]['transcript'] ?? '';

        if ('' === trim($text)) {
            return new \WP_Error('EMPTY_RESPONSE', 'No transcript text was returned.');
        }

        return ['text' => $text, 'model' => $model];
    }
}