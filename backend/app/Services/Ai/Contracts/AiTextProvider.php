<?php

declare(strict_types=1);

namespace App\Services\Ai\Contracts;

/**
 * A narration provider turns a computed metrics/context payload into prose.
 * Implementations range from a zero-dependency local template engine to a
 * hosted LLM. The statistical engines never depend on this — it only polishes
 * the AI Reports narrative and the chat/voice assistant's spoken answer.
 */
interface AiTextProvider
{
    /** Machine name, e.g. "local", "openai", "anthropic". */
    public function name(): string;

    /** Whether this provider has enough configuration to actually run. */
    public function available(): bool;

    /**
     * Produce a completion for the given system instruction + user prompt.
     * Must never throw for transport errors — return null so the caller can
     * fall back to the deterministic local narrative.
     */
    public function complete(string $system, string $prompt): ?string;
}
