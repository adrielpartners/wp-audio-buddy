<?php

if (! defined('ABSPATH')) {
    exit;
}

final class WPAB_ExcerptService
{
    public function __construct(private WPAB_Settings $settings)
    {
    }

    public function handle(int $attachment_id): void
    {
        $transcript = (string) get_post_meta($attachment_id, WPAB_Meta::TRANSCRIPT, true);
        if ('' === trim($transcript)) {
            return;
        }

        if ('done' === WPAB_Meta::excerpt_status($attachment_id) && '' !== trim((string) get_post_meta($attachment_id, WPAB_Meta::EXCERPT, true))) {
            return;
        }

        update_post_meta($attachment_id, WPAB_Meta::EXCERPT_STATUS, 'running');

        $prompt_type = (string) $this->settings->get('excerpt_type', 'informative');
        $custom_prompt = (string) $this->settings->get('excerpt_custom_prompt', '');
        $max_words = (int) $this->settings->get('excerpt_max_words', 100);
        $temperature = (float) $this->settings->get('excerpt_temperature', 0.2);

        $prompt = $this->base_prompt($prompt_type, $custom_prompt);
        $response = $this->responses_api($prompt . "\n\nTranscript:\n" . $transcript, $max_words, $temperature);

        if (is_wp_error($response)) {
            update_post_meta($attachment_id, WPAB_Meta::EXCERPT_STATUS, 'error');
            update_post_meta($attachment_id, WPAB_Meta::TRANSCRIPT_ERROR, $response->get_error_message());
            return;
        }

        update_post_meta($attachment_id, WPAB_Meta::EXCERPT, $response);
        update_post_meta($attachment_id, WPAB_Meta::EXCERPT_STATUS, 'done');
        update_post_meta($attachment_id, WPAB_Meta::EXCERPT_MODEL, $this->settings->get('excerpt_model'));
        update_post_meta($attachment_id, WPAB_Meta::EXCERPT_PROMPT_TYPE, $prompt_type);
        update_post_meta($attachment_id, WPAB_Meta::EXCERPT_PROMPT_CUSTOM, $custom_prompt);
        update_post_meta($attachment_id, WPAB_Meta::EXCERPT_UPDATED, current_time('mysql'));
    }

    public function format_transcript(string $transcript): string
    {
        $response = $this->responses_api(
            "Format this transcript into readable paragraphs while preserving meaning and wording. Output plain text only.\n\n" . $transcript,
            1500,
            0.2
        );

        return is_wp_error($response) ? $transcript : $response;
    }

    private function base_prompt(string $type, string $custom_prompt): string
    {
        return match ($type) {
            'engaging' => 'Write an engaging, welcoming invitation encouraging someone to listen to or engage with this audio content.',
            'custom' => $custom_prompt ?: 'Summarize the content in plain language.',
            default => 'Summarize the following transcript clearly and concisely for a general audience.',
        };
    }

    private function responses_api(string $input, int $max_words, float $temperature): string|WP_Error
    {
        $api_key = (string) $this->settings->get('api_key', '');
        if ('' === $api_key) {
            return new WP_Error('wpab_missing_key', 'OpenAI API key is missing.');
        }

        $model = (string) $this->settings->get('excerpt_model', 'gpt-5-nano');
        $payload = [
            'model' => $model,
            'input' => [
                [
                    'role' => 'system',
                    'content' => [
                        [
                            'type' => 'input_text',
                            'text' => 'Output plain text only. Maximum ' . $max_words . ' words.',
                        ],
                    ],
                ],
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'input_text',
                            'text' => $input,
                        ],
                    ],
                ],
            ],
            'temperature' => $temperature,
        ];

        $res = wp_remote_post('https://api.openai.com/v1/responses', [
            'timeout' => 120,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode($payload),
        ]);

        if (is_wp_error($res)) {
            return $res;
        }

        $status = wp_remote_retrieve_response_code($res);
        $body = json_decode((string) wp_remote_retrieve_body($res), true);

        if ($status >= 400) {
            return new WP_Error('wpab_openai_error', $body['error']['message'] ?? 'OpenAI request failed.');
        }

        $text = (string) ($body['output_text'] ?? '');
        if ('' === trim($text) && ! empty($body['output'][0]['content'][0]['text'])) {
            $text = (string) $body['output'][0]['content'][0]['text'];
        }

        if ('' === trim($text)) {
            return new WP_Error('wpab_empty_response', 'No text returned from OpenAI.');
        }

        return trim($text);
    }
}
