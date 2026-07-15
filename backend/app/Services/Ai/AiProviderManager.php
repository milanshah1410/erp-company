<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\Setting;
use App\Services\Ai\Contracts\AiTextProvider;
use App\Services\Ai\Providers\AnthropicProvider;
use App\Services\Ai\Providers\LocalProvider;
use App\Services\Ai\Providers\OpenAiProvider;

/**
 * Resolves the active narration provider from tenant Settings (with an env/config
 * fallback), mirroring how SmsService reads `sms_driver`. If the configured
 * hosted provider is missing its API key we transparently fall back to the local
 * deterministic narrator so nothing ever hard-fails on configuration.
 *
 * Settings keys: ai_provider (local|openai|anthropic), ai_api_key, ai_model.
 */
class AiProviderManager
{
    private readonly LocalProvider $local;

    public function __construct()
    {
        $this->local = new LocalProvider;
    }

    /** The configured provider name (may differ from what actually runs). */
    public function configuredDriver(): string
    {
        return Setting::get('ai_provider', (string) config('ai.provider', 'local')) ?: 'local';
    }

    /** True when a hosted model will actually be used (key present). */
    public function usingHostedModel(): bool
    {
        return $this->provider()->name() !== 'local';
    }

    /** The provider that will actually be used, after key-availability checks. */
    public function provider(): AiTextProvider
    {
        $driver = $this->configuredDriver();

        $provider = match ($driver) {
            'openai' => new OpenAiProvider(
                $this->key('openai'),
                Setting::get('ai_model') ?: (string) config('ai.openai.model'),
                (string) config('ai.openai.base_url'),
            ),
            'anthropic' => new AnthropicProvider(
                $this->key('anthropic'),
                Setting::get('ai_model') ?: (string) config('ai.anthropic.model'),
                (string) config('ai.anthropic.base_url'),
                (string) config('ai.anthropic.version'),
            ),
            default => $this->local,
        };

        return $provider->available() ? $provider : $this->local;
    }

    /**
     * Narrate: try the active provider, always fall back to the deterministic
     * local narrative (which the caller supplies as $fallback / the prompt body).
     */
    public function narrate(string $system, string $prompt, string $fallback): string
    {
        $result = $this->provider()->complete($system, $prompt);

        return ($result !== null && $result !== '') ? $result : $fallback;
    }

    private function key(string $driver): string
    {
        return (string) (Setting::get('ai_api_key') ?: config("ai.{$driver}.api_key", ''));
    }
}
