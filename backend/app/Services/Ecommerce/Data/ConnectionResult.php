<?php

declare(strict_types=1);

namespace App\Services\Ecommerce\Data;

/** Outcome of a connector's connection test. */
class ConnectionResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly ?string $message = null,
        public readonly array $meta = [],
    ) {}

    public static function ok(?string $message = null, array $meta = []): self
    {
        return new self(true, $message, $meta);
    }

    public static function fail(string $message): self
    {
        return new self(false, $message);
    }
}
