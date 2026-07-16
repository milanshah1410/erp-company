<?php

declare(strict_types=1);

namespace App\Models\Concerns;

/**
 * Pins a model to the landlord/central database connection. Driven by config
 * (saas.central_connection, default `central`) so the test suite — which runs
 * on a single shared sqlite connection — and any future split-DB deployment can
 * both override it, while production keeps the dedicated `central` connection.
 */
trait UsesCentralConnection
{
    public function getConnectionName(): ?string
    {
        return config('saas.central_connection', 'central');
    }
}
