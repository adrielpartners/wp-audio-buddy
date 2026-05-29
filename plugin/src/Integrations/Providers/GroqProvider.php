<?php

declare(strict_types=1);

namespace AdrielPartners\WpAudioBuddy\Integrations\Providers;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Groq provider — OpenAI-compatible API for transcription + text generation.
 *
 * Reuses the same HTTP patterns as OpenAIProvider but with a different
 * base endpoint URL and curated model lists.
 */
final class GroqProvider implements TranscriptionProviderInterface, TextGenerationProviderInterface
{
    public function transcribe(string $file_path, string $mime, array $config): array|\WP_Error
    {
        if (! empty($config['endpoint'])) {
            // Already has Groq endpoint from registry — use OpenAIProvider under the hood.
            $openai = new OpenAIProvider();
            return $openai->transcribe($file_path, $mime, $config);
        }

        $config['endpoint'] = 'https://api.groq.com/openai/v1';
        $openai = new OpenAIProvider();
        return $openai->transcribe($file_path, $mime, $config);
    }

    public function generate(string $prompt, array $config): string|\WP_Error
    {
        if (! empty($config['endpoint'])) {
            $openai = new OpenAIProvider();
            return $openai->generate($prompt, $config);
        }

        $config['endpoint'] = 'https://api.groq.com/openai/v1';
        $openai = new OpenAIProvider();
        return $openai->generate($prompt, $config);
    }
}