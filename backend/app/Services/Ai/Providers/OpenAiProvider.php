<?php

declare(strict_types=1);

namespace App\Services\Ai\Providers;

use App\Services\Ai\Contracts\AiTextProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * OpenAI Chat Completions narrator. Enabled when `ai_provider` = openai and an
 * API key is present (Settings → AI, or the OPENAI_API_KEY env var). Transport
 * failures are swallowed (logged + null) so the caller falls back to the local
 * deterministic narrative — an AI outage never breaks a report or the assistant.
 */
class OpenAiProvider implements AiTextProvider
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $model,
        private readonly string $baseUrl,
    ) {}

    public function name(): string
    {
        return 'openai';
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
            $response = Http::withToken($this->apiKey)
                ->timeout(30)
                ->post(rtrim($this->baseUrl, '/').'/chat/completions', [
                    'model' => $this->model,
                    'temperature' => 0.3,
                    'messages' => [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user', 'content' => $prompt],
                    ],
                ])
                ->throw()
                ->json();

            $text = $response['choices'][0]['message']['content'] ?? null;

            return is_string($text) ? trim($text) : null;
        } catch (\Throwable $e) {
            Log::warning('AI OpenAI narration failed: '.$e->getMessage());

            return null;
        }
    }
}
