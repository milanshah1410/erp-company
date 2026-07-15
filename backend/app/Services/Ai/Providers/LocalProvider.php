<?php

declare(strict_types=1);

namespace App\Services\Ai\Providers;

use App\Services\Ai\Contracts\AiTextProvider;

/**
 * Zero-dependency, no-op narrator. It has no generative ability, so it always
 * returns null — signalling AiProviderManager to use the deterministic fallback
 * narrative that every caller composes from the same on-screen numbers. This is
 * the default provider, which is why every AI screen is fully functional (and
 * fully offline) with no configuration; the hosted providers only add
 * conversational polish on top of that identical fallback text.
 */
class LocalProvider implements AiTextProvider
{
    public function name(): string
    {
        return 'local';
    }

    public function available(): bool
    {
        return true;
    }

    public function complete(string $system, string $prompt): ?string
    {
        return null;
    }
}
