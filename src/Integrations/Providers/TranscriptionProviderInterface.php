<?php

declare(strict_types=1);

namespace AdrielPartners\WpAudioBuddy\Integrations\Providers;

if (! defined('ABSPATH')) {
    exit;
}

interface TranscriptionProviderInterface
{
    /**
     * Transcribe an audio file.
     *
     * @param string $file_path Absolute path to the audio file.
     * @param string $mime      MIME type of the audio file.
     * @param array  $config    Provider configuration (api_key, model, endpoint, etc.).
     *
     * @return array{text: string, segments?: array}|\WP_Error
     */
    public function transcribe(string $file_path, string $mime, array $config): array|\WP_Error;
}