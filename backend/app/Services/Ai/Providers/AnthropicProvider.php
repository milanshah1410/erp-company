<?php

declare(strict_types=1);

namespace App\Services\Ai\Providers;

use App\Services\Ai\Contracts\AiTextProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Anthropic Messages API narrator. Enabled when `ai_provider` = anthropic and an
 * API key is present (Settings → AI, or the ANTHROPIC_API_KEY env var). Like the
 * OpenAI provider, transport failures fall back to the local narrative.
 */
class AnthropicProvider implements AiTextProvider
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $model,
        private readonly string $baseUrl,
        private readonly string $version,
    ) {}

    public function name(): string
    {
        return 'anthropic';
    }

    public function available(): bool
    {
        return $this->apiKey !== '';
    }

    public function complete(string $system, string $prompt): ?string
    {
        if (! $this->available()) {
            return null;
        }

        try {
            $response = Http::withHeaders([
                'x-api-key' => $this->apiKey,
                'anthropic-version' => $this->version,
            ])
                ->timeout(30)
                ->post(rtrim($this->baseUrl, '/').'/messages', [
                    'model' => $this->model,
                    'max_tokens' => 1024,
                    'temperature' => 0.3,
                    'system' => $system,
                    'messages' => [
                        ['role' => 'user', 'content' => $prompt],
                    ],
                ])
                ->throw()
                ->json();

            $text = $response['content'][0]['text'] ?? null;

            return is_string($text) ? trim($text) : null;
        } catch (\Throwable $e) {
            Log::warning('AI Anthropic narration failed: '.$e->getMessage());

            return null;
        }
    }
}
