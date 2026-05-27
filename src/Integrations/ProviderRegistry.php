<?php

declare(strict_types=1);

namespace AdrielPartners\WpAudioBuddy\Integrations;

use AdrielPartners\WpAudioBuddy\Integrations\Providers\AnthropicProvider;
use AdrielPartners\WpAudioBuddy\Integrations\Providers\DeepgramProvider;
use AdrielPartners\WpAudioBuddy\Integrations\Providers\DeepSeekProvider;
use AdrielPartners\WpAudioBuddy\Integrations\Providers\GeminiProvider;
use AdrielPartners\WpAudioBuddy\Integrations\Providers\GroqProvider;
use AdrielPartners\WpAudioBuddy\Integrations\Providers\OpenAIProvider;
use AdrielPartners\WpAudioBuddy\Integrations\Providers\OpenRouterProvider;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Central registry of AI providers.
 *
 * Maps provider slugs to their implementing classes,
 * supported operations, curated model lists, and metadata.
 */
final class ProviderRegistry
{
    private const TRANSCRIPTION = 'transcription';
    private const TEXT = 'text';

    private const PROVIDERS = [
        'openai' => [
            'label' => 'OpenAI',
            self::TRANSCRIPTION => OpenAIProvider::class,
            self::TEXT => OpenAIProvider::class,
            'default_model_transcription' => 'gpt-4o-mini-transcribe',
            'default_model_text' => 'gpt-4o-mini',
            'models_transcription' => ['gpt-4o-mini-transcribe', 'gpt-4o-transcribe'],
            'models_text' => [
                'gpt-4o-mini' => 'GPT-4o Mini ($0.15/M)',
                'gpt-4o' => 'GPT-4o ($2.50/M)',
            ],
            'endpoint' => 'https://api.openai.com',
            'docs_url' => 'https://platform.openai.com/api-keys',
        ],
        'groq' => [
            'label' => 'Groq',
            self::TRANSCRIPTION => GroqProvider::class,
            self::TEXT => GroqProvider::class,
            'default_model_transcription' => 'whisper-large-v3-turbo',
            'default_model_text' => 'qwen3-32b',
            'models_transcription' => ['whisper-large-v3-turbo', 'whisper-large-v3'],
            'models_text' => [
                'qwen3-32b' => 'Qwen3 32B ($0.29/M)',
                'llama-4-scout-17b-16e-instruct' => 'Llama 4 Scout 17B ($0.11/M)',
                'llama-3.3-70b-versatile' => 'Llama 3.3 70B ($0.59/M)',
                'llama-3.1-8b-instant' => 'Llama 3.1 8B ($0.05/M)',
            ],
            'endpoint' => 'https://api.groq.com/openai',
            'docs_url' => 'https://console.groq.com/keys',
        ],
        'openrouter' => [
            'label' => 'OpenRouter',
            self::TRANSCRIPTION => null,
            self::TEXT => OpenRouterProvider::class,
            'default_model_text' => 'openai/gpt-4o-mini',
            'models_text' => [
                'openai/gpt-4o-mini' => 'GPT-4o Mini ($0.15/M)',
                'openai/gpt-4o' => 'GPT-4o ($2.50/M)',
                'deepseek/deepseek-chat' => 'DeepSeek V3 ($0.27/M)',
                'deepseek/deepseek-r1' => 'DeepSeek R1 ($0.55/M)',
                'qwen/qwen2.5-72b-instruct' => 'Qwen 2.5 72B ($0.23/M)',
                'qwen/qwq-32b-preview' => 'QwQ 32B Reasoning ($0.30/M)',
                'google/gemini-2.0-flash-001' => 'Gemini 2.0 Flash ($0.10/M)',
                'anthropic/claude-3-haiku' => 'Claude 3 Haiku ($0.25/M)',
                'moonshot/moonshot-v1-8k' => 'Moonshot v1 8K ($0.50/M)',
            ],
            'endpoint' => 'https://openrouter.ai/api',
            'docs_url' => 'https://openrouter.ai/keys',
        ],
        'anthropic' => [
            'label' => 'Anthropic',
            self::TRANSCRIPTION => null,
            self::TEXT => AnthropicProvider::class,
            'default_model_text' => 'claude-3-haiku-20240307',
            'models_text' => [
                'claude-3-haiku-20240307' => 'Claude 3 Haiku ($0.25/M)',
                'claude-3-5-sonnet-20241022' => 'Claude 3.5 Sonnet ($3.00/M)',
                'claude-opus-4-20250514' => 'Claude Opus 4 ($15.00/M)',
            ],
            'endpoint' => 'https://api.anthropic.com',
            'docs_url' => 'https://console.anthropic.com/settings/keys',
        ],
        'deepseek' => [
            'label' => 'DeepSeek',
            self::TRANSCRIPTION => null,
            self::TEXT => DeepSeekProvider::class,
            'default_model_text' => 'deepseek-chat',
            'models_text' => [
                'deepseek-chat' => 'DeepSeek V3 ($0.27/M)',
                'deepseek-reasoner' => 'DeepSeek R1 ($0.55/M)',
            ],
            'endpoint' => 'https://api.deepseek.com',
            'docs_url' => 'https://platform.deepseek.com/api_keys',
        ],
        'deepgram' => [
            'label' => 'Deepgram',
            self::TRANSCRIPTION => DeepgramProvider::class,
            self::TEXT => null,
            'default_model_transcription' => 'nova-2',
            'models_transcription' => ['nova-2', 'whisper'],
            'endpoint' => 'https://api.deepgram.com',
            'docs_url' => 'https://console.deepgram.com/api-keys',
        ],
    ];

    /**
     * Get the transcription provider instance for a given slug.
     */
    public static function getTranscriptionProvider(string $slug): ?TranscriptionProviderInterface
    {
        $info = self::PROVIDERS[$slug] ?? null;
        if ($info === null || $info[self::TRANSCRIPTION] === null) {
            return null;
        }
        $class = $info[self::TRANSCRIPTION];
        return new $class();
    }

    /**
     * Get the text generation provider instance for a given slug.
     */
    public static function getTextGenerationProvider(string $slug): ?TextGenerationProviderInterface
    {
        $info = self::PROVIDERS[$slug] ?? null;
        if ($info === null || $info[self::TEXT] === null) {
            return null;
        }
        $class = $info[self::TEXT];
        return new $class();
    }

    /**
     * Get all providers that support a given operation.
     *
     * @return array<string, string> slug => label
     */
    public static function getAvailableProviders(string $operation): array
    {
        $result = [];
        foreach (self::PROVIDERS as $slug => $info) {
            $class = $info[$operation] ?? null;
            if ($class !== null) {
                $result[$slug] = $info['label'];
            }
        }
        return $result;
    }

    /**
     * Get full metadata for a provider slug.
     */
    public static function getProviderInfo(string $slug): ?array
    {
        return self::PROVIDERS[$slug] ?? null;
    }

    /**
     * Get the curated model list for a provider and operation.
     *
     * @return array<string, string> model_slug => display_label
     */
    public static function getModels(string $slug, string $operation): array
    {
        $info = self::PROVIDERS[$slug] ?? null;
        if ($info === null) {
            return [];
        }
        $key = $operation === self::TRANSCRIPTION ? 'models_transcription' : 'models_text';
        return $info[$key] ?? [];
    }

    /**
     * Get base endpoint URL for a provider.
     */
    public static function getEndpoint(string $slug): string
    {
        return self::PROVIDERS[$slug]['endpoint'] ?? 'https://api.openai.com';
    }

    /**
     * Check whether a provider has an OpenAI-compatible API (can use shared HTTP client).
     */
    public static function isOpenAICompatible(string $slug): bool
    {
        return in_array($slug, ['openai', 'groq', 'openrouter', 'deepseek'], true);
    }
}