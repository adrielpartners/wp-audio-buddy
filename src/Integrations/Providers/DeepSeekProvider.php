<?php

declare(strict_types=1);

namespace AdrielPartners\WpAudioBuddy\Integrations\Providers;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * DeepSeek provider — text generation only, OpenAI-compatible API.
 */
final class DeepSeekProvider implements TextGenerationProviderInterface
{
    public function generate(string $prompt, array $config): string|\WP_Error
    {
        $config['endpoint'] = $config['endpoint'] ?? 'https://api.deepseek.com';
        $openai = new OpenAIProvider();
        return $openai->generate($prompt, $config);
    }
}