<?php

declare(strict_types=1);

namespace AdrielPartners\WpAudioBuddy\Integrations\Providers;

if (! defined('ABSPATH')) {
    exit;
}

interface TextGenerationProviderInterface
{
    /**
     * Generate text (excerpt/summary) from a prompt.
     *
     * @param string $prompt The input prompt (includes transcript and instructions).
     * @param array  $config Provider configuration (api_key, model, endpoint, etc.).
     *
     * @return string|\WP_Error The generated text or error.
     */
    public function generate(string $prompt, array $config): string|\WP_Error;
}