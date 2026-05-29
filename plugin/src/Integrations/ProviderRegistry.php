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
use AdrielPartners\WpAudioBuddy\Integrations\Providers\TextGenerationProviderInterface;
use AdrielPartners\WpAudioBuddy\Integrations\Providers\TranscriptionProviderInterface;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Central registry of AI providers.
 *
 * Provider definitions (labels, models, pricing) are stored in
 * config/providers.php. Edit that file to add or change models.
 */
final class ProviderRegistry
{
    private const TRANSCRIPTION = 'transcription';
    private const TEXT = 'text';

    /**
     * Class map: provider slug => implementing class FQCN.
     */
    private const CLASS_MAP = [
        'openai'     => OpenAIProvider::class,
        'groq'       => GroqProvider::class,
        'openrouter' => OpenRouterProvider::class,
        'anthropic'  => AnthropicProvider::class,
        'deepseek'   => DeepSeekProvider::class,
        'gemini'     => GeminiProvider::class,
        'deepgram'   => DeepgramProvider::class,
    ];

    /**
     * Load provider definitions from config/providers.php.
     */
    private static function load(): array
    {
        static $providers = null;
        if ($providers === null) {
            $path = defined('WPAB_PATH') ? WPAB_PATH . 'config/providers.php' : __DIR__ . '/../../config/providers.php';
            if (file_exists($path)) {
                $providers = (array) require $path;
            } else {
                $providers = [];
            }
        }
        return $providers;
    }

    // ─── Public API ──────────────────────────────────────────────────────────

    /**
     * Get the list of providers that support a given operation.
     * Returns slug => label pairs for the settings dropdown.
     */
    public static function getAvailableProviders(string $operation): array
    {
        $all = self::load();
        $result = [];
        foreach ($all as $slug => $info) {
            $op = $info[$operation] ?? null;
            if ($op !== null && isset($op['class'])) {
                $result[$slug] = $info['label'] ?? $slug;
            }
        }
        return $result;
    }

    /**
     * Get the transcription provider instance for a given slug.
     */
    public static function getTranscriptionProvider(string $slug): ?TranscriptionProviderInterface
    {
        $all = self::load();
        $info = $all[$slug] ?? null;
        if ($info === null) {
            return null;
        }
        $op = $info['transcription'] ?? null;
        if ($op === null || ! isset($op['class'])) {
            return null;
        }
        $class = $op['class'];
        if (! class_exists($class)) {
            return null;
        }
        return new $class();
    }

    /**
     * Get the text generation provider instance for a given slug.
     */
    public static function getTextGenerationProvider(string $slug): ?TextGenerationProviderInterface
    {
        $all = self::load();
        $info = $all[$slug] ?? null;
        if ($info === null) {
            return null;
        }
        $op = $info['text'] ?? null;
        if ($op === null || ! isset($op['class'])) {
            return null;
        }
        $class = $op['class'];
        if (! class_exists($class)) {
            return null;
        }
        return new $class();
    }

    /**
     * Get metadata about a provider.
     */
    public static function getProviderInfo(string $slug): ?array
    {
        $all = self::load();
        $info = $all[$slug] ?? null;
        if ($info === null) {
            return null;
        }
        $models = $info['transcription']['models'] ?? [];
        $text_models = $info['text']['models'] ?? [];

        return [
            'label'                      => $info['label'] ?? $slug,
            'endpoint'                   => $info['endpoint'] ?? '',
            'docs_url'                   => $info['docs_url'] ?? '',
            'transcription'              => $info['transcription'] ?? [],
            'text'                       => $info['text'] ?? [],
            'models_transcription'       => self::formatModels($models, 'transcription'),
            'models_text'                => self::formatModels($text_models, 'text'),
            'default_model_transcription'=> $info['transcription']['default_model'] ?? '',
            'default_model_text'         => $info['text']['default_model'] ?? '',
            'compat'                     => $info['endpoint'] ?? false,
        ];
    }

    /**
     * Get the default endpoint URL for a provider.
     */
    public static function getEndpoint(string $slug): string
    {
        $all = self::load();
        return (string) ($all[$slug]['endpoint'] ?? 'https://api.openai.com');
    }

    /**
     * Get formatted model options for a provider + operation.
     * Returns slug => label pairs for the settings dropdown.
     */
    public static function getModels(string $slug, string $operation): array
    {
        $info = self::getProviderInfo($slug);
        if ($info === null) {
            return [];
        }
        return $operation === self::TRANSCRIPTION
            ? $info['models_transcription']
            : $info['models_text'];
    }

    /**
     * Whether a provider uses an OpenAI-compatible API format.
     */
    public static function isOpenAICompatible(string $slug): bool
    {
        return in_array($slug, ['openai', 'groq', 'openrouter', 'deepseek'], true);
    }

    // ─── Internal helpers ────────────────────────────────────────────────────

    /**
     * Format model arrays into slug => display_label pairs.
     */
    private static function formatModels(array $models, string $type): array
    {
        $result = [];
        foreach ($models as $slug => $config) {
            if ($type === self::TRANSCRIPTION) {
                // Show per-minute pricing for transcription models.
                $cost = $config['cost'] ?? '';
                $label = $cost ? "$slug — \${$cost}" : $slug;
            } else {
                // Show input/output pricing for text models.
                $in  = $config['cost_in'] ?? null;
                $out = $config['cost_out'] ?? null;
                if ($in !== null && $out !== null) {
                    $label = "$slug — \${$in}/$out";
                } else {
                    $label = $slug;
                }
            }
            $result[$slug] = $label;
        }
        return $result;
    }
}