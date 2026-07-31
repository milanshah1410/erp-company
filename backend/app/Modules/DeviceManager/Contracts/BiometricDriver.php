<?php

declare(strict_types=1);

namespace App\Modules\DeviceManager\Contracts;

use App\Modules\DeviceManager\Data\PunchRecord;
use DateTimeInterface;

/** Fingerprint / face attendance terminals. */
interface BiometricDriver extends DeviceDriver
{
    /**
     * Pull attendance logs recorded since $since (a recent window when null).
     *
     * @return PunchRecord[]
     */
    public function fetchPunches(?DateTimeInterface $since = null): array;

    /**
     * Normalise the brand's own attendance-log rows.
     *
     * @param  array<int,array<string,mixed>>  $rows
     * @return PunchRecord[]
     */
    public function parseLogs(array $rows): array;

    /**
     * Fake punches — the Simulator's "Virtual Fingerprint Event", which lets the
     * whole punch → attendance → payroll chain be tested with no terminal.
     *
     * @param  string[]|null  $deviceUserIds
     * @return PunchRecord[]
     */
    public function simulatePunches(?array $deviceUserIds = null, int $count = 2, string $direction = 'in'): array;
}
