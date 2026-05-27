<?php
/**
 * WP Audio Buddy — Provider Configuration
 *
 * =============================================================================
 * HOW TO ADD OR EDIT MODELS
 * =============================================================================
 *
 * Transcription models have a `cost` field shown as "per minute" of audio.
 * Text generation models have `cost_in` and `cost_out` per 1M tokens.
 *
 * To add a new model:
 *   1. Add its slug as the array key
 *   2. Set cost (transcription) or cost_in/cost_out (text)
 *   3. The label is auto-generated from the slug
 *
 * To add a new provider:
 *   1. Add a new top-level key with the provider slug
 *   2. Give it a 'label', 'endpoint', 'docs_url'
 *   3. Set 'transcription' and/or 'text' with class + models
 *   4. Set 'transcription' to null if it doesn't support audio
 *   5. Set 'text' to null if it doesn't support text generation
 *
 * =============================================================================
 * PRICING SOURCES
 * =============================================================================
 *
 * Text model pricing comes from OpenRouter's model list API
 * (https://openrouter.ai/api/v1/models). Prices shown are per 1M tokens.
 *
 * Transcription pricing is hardcoded from provider pricing pages since
 * no standard API exists for audio transcription pricing.
 *
 * =============================================================================
 */

return [

    // ─── OpenAI ───────────────────────────────────────────────────────────────
    'openai' => [
        'label'     => 'OpenAI',
        'endpoint'  => 'https://api.openai.com',
        'docs_url'  => 'https://platform.openai.com/api-keys',

        // OpenAI has both transcription and text models
        'transcription' => [
            'class'          => \AdrielPartners\WpAudioBuddy\Integrations\Providers\OpenAIProvider::class,
            'default_model'  => 'gpt-4o-mini-transcribe',
            'models'         => [
                'gpt-4o-mini-transcribe' => ['cost' => '0.36/min'],       // $0.36 per minute of audio
                'gpt-4o-transcribe'      => ['cost' => '0.72/min'],       // $0.72 per minute of audio
            ],
        ],
        'text' => [
            'class'          => \AdrielPartners\WpAudioBuddy\Integrations\Providers\OpenAIProvider::class,
            'default_model'  => 'gpt-4o-mini',
            'models'         => [
                // slug => [cost_in (input), cost_out (output)] per 1M tokens
                'gpt-4o-mini'       => ['cost_in' => 0.15, 'cost_out' => 0.60],  // GPT-4o Mini ($0.15/$0.60)
                'gpt-4o'            => ['cost_in' => 2.50, 'cost_out' => 10.00], // GPT-4o ($2.50/$10.00)
                'gpt-4o-audio'      => ['cost_in' => 2.50, 'cost_out' => 10.00], // GPT-4o Audio ($2.50/$10.00)
                'gpt-audio'         => ['cost_in' => 2.50, 'cost_out' => 10.00], // GPT Audio ($2.50/$10.00)
                'gpt-audio-mini'    => ['cost_in' => 0.60, 'cost_out' => 2.40],  // GPT Audio Mini ($0.60/$2.40)
            ],
        ],
    ],

    // ─── Groq ───────────────────────────────────────────────────────────────
    'groq' => [
        'label'     => 'Groq',
        'endpoint'  => 'https://api.groq.com/openai',
        'docs_url'  => 'https://console.groq.com/keys',

        'transcription' => [
            'class'          => \AdrielPartners\WpAudioBuddy\Integrations\Providers\GroqProvider::class,
            'default_model'  => 'whisper-large-v3-turbo',
            'models'         => [
                'whisper-large-v3-turbo' => ['cost' => '0.04/min'],  // $0.04 per minute
                'whisper-large-v3'       => ['cost' => '0.06/min'],  // $0.06 per minute
            ],
        ],
        'text' => [
            'class'          => \AdrielPartners\WpAudioBuddy\Integrations\Providers\GroqProvider::class,
            'default_model'  => 'qwen3-32b',
            'models'         => [
                'qwen3-32b'                         => ['cost_in' => 0.29, 'cost_out' => 0.59],  // Qwen3 32B ($0.29/$0.59)
                'llama-4-scout-17b-16e-instruct'    => ['cost_in' => 0.11, 'cost_out' => 0.34],  // Llama 4 Scout ($0.11/$0.34)
                'llama-3.3-70b-versatile'           => ['cost_in' => 0.59, 'cost_out' => 0.79],  // Llama 3.3 70B ($0.59/$0.79)
                'llama-3.1-8b-instant'              => ['cost_in' => 0.05, 'cost_out' => 0.08],  // Llama 3.1 8B ($0.05/$0.08)
            ],
        ],
    ],

    // ─── OpenRouter ──────────────────────────────────────────────────────────
    'openrouter' => [
        'label'     => 'OpenRouter',
        'endpoint'  => 'https://openrouter.ai/api',
        'docs_url'  => 'https://openrouter.ai/keys',

        // OpenRouter supports transcription via their /v1/audio/transcriptions endpoint
        // and offers multimodal audio models for chat completions.
        'transcription' => [
            'class'          => \AdrielPartners\WpAudioBuddy\Integrations\Providers\OpenRouterProvider::class,
            'default_model'  => 'openai/whisper-1',
            'models'         => [
                'nvidia/parakeet-tdt-0.6b-v3'       => ['cost' => '0.0015/min'], // NVIDIA: Parakeet TDT 0.6B v3
                'mistralai/voxtral-mini-transcribe' => ['cost' => '0.003/min'], // Mistral: Voxtral Mini Transcribe
                'qwen/qwen3-asr-flash-2026-02-10'   => ['cost' => '0.0021/min'], // Qwen: Qwen3 ASR Flash
                'google/chirp-3'                    => ['cost' => '0.0016/min'], // Google: Chirp 3
                'openai/whisper-large-v3-turbo'     => ['cost' => '0.04/min'],  // OpenAI: Whisper Large V3 Turbo
                'openai/whisper-large-v3'           => ['cost' => '0.0015/min'],  // OpenAI: Whisper Large V3
                'openai/whisper-1'                  => ['cost' => '0.006/min'],  // OpenAI: Whisper 1
            ],
        ],
        'text' => [
            'class'          => \AdrielPartners\WpAudioBuddy\Integrations\Providers\OpenRouterProvider::class,
            'default_model'  => 'openai/gpt-4o-mini',
            'models'         => [
                'openai/gpt-4o-mini'         => ['cost_in' => 0.15, 'cost_out' => 0.60],  // GPT-4o Mini
                'openai/gpt-4o'              => ['cost_in' => 2.50, 'cost_out' => 10.00], // GPT-4o
                'deepseek/deepseek-chat'     => ['cost_in' => 0.23, 'cost_out' => 0.91],  // DeepSeek V3
                'deepseek/deepseek-r1'       => ['cost_in' => 0.70, 'cost_out' => 2.50],  // DeepSeek R1
                'qwen/qwen-2.5-72b-instruct' => ['cost_in' => 0.36, 'cost_out' => 0.40],  // Qwen 2.5 72B
                'qwen/qwen3-32b'             => ['cost_in' => 0.08, 'cost_out' => 0.28],  // Qwen3 32B
                'google/gemini-2.0-flash-001'=> ['cost_in' => 0.10, 'cost_out' => 0.40],  // Gemini 2.0 Flash
                'anthropic/claude-3-haiku'   => ['cost_in' => 0.25, 'cost_out' => 1.25],  // Claude 3 Haiku
                'anthropic/claude-3-5-sonnet-20241022' => ['cost_in' => 3.00, 'cost_out' => 15.00], // Claude 3.5 Sonnet
                'moonshotai/kimi-k2'         => ['cost_in' => 0.57, 'cost_out' => 2.30],  // Kimi K2
            ],
        ],
    ],

    // ─── Anthropic ────────────────────────────────────────────────────────────
    'anthropic' => [
        'label'     => 'Anthropic',
        'endpoint'  => 'https://api.anthropic.com',
        'docs_url'  => 'https://console.anthropic.com/settings/keys',

        'transcription' => null,  // Anthropic does not offer audio transcription

        'text' => [
            'class'          => \AdrielPartners\WpAudioBuddy\Integrations\Providers\AnthropicProvider::class,
            'default_model'  => 'claude-3-haiku-20240307',
            'models'         => [
                'claude-3-haiku-20240307'        => ['cost_in' => 0.25, 'cost_out' => 1.25],  // Claude 3 Haiku
                'claude-3-5-sonnet-20241022'     => ['cost_in' => 3.00, 'cost_out' => 15.00], // Claude 3.5 Sonnet
                'claude-opus-4-20250514'         => ['cost_in' => 15.00, 'cost_out' => 75.00],// Claude Opus 4
            ],
        ],
    ],

    // ─── DeepSeek ─────────────────────────────────────────────────────────────
    'deepseek' => [
        'label'     => 'DeepSeek',
        'endpoint'  => 'https://api.deepseek.com',
        'docs_url'  => 'https://platform.deepseek.com/api_keys',

        'transcription' => null,  // DeepSeek does not offer audio transcription

        'text' => [
            'class'          => \AdrielPartners\WpAudioBuddy\Integrations\Providers\DeepSeekProvider::class,
            'default_model'  => 'deepseek-chat',
            'models'         => [
                'deepseek-chat'     => ['cost_in' => 0.27, 'cost_out' => 1.10],  // DeepSeek V3
                'deepseek-reasoner' => ['cost_in' => 0.55, 'cost_out' => 2.19],  // DeepSeek R1
            ],
        ],
    ],

    // ─── Google Gemini ────────────────────────────────────────────────────────
    'gemini' => [
        'label'     => 'Google Gemini',
        'endpoint'  => 'https://generativelanguage.googleapis.com',
        'docs_url'  => 'https://aistudio.google.com/app/apikey',

        'transcription' => null,  // Gemini does not offer audio transcription

        'text' => [
            'class'          => \AdrielPartners\WpAudioBuddy\Integrations\Providers\GeminiProvider::class,
            'default_model'  => 'gemini-2.0-flash-001',
            'models'         => [
                'gemini-2.0-flash-001' => ['cost_in' => 0.10, 'cost_out' => 0.40],  // Gemini 2.0 Flash
            ],
        ],
    ],

    // ─── Deepgram ─────────────────────────────────────────────────────────────
    'deepgram' => [
        'label'     => 'Deepgram',
        'endpoint'  => 'https://api.deepgram.com',
        'docs_url'  => 'https://console.deepgram.com/signup',

        'transcription' => [
            'class'          => \AdrielPartners\WpAudioBuddy\Integrations\Providers\DeepgramProvider::class,
            'default_model'  => 'nova-2',
            'models'         => [
                'nova-2' => ['cost' => '0.16/min'],  // $0.16 per minute
            ],
        ],
        'text' => null,  // Deepgram does not offer text generation
    ],
];