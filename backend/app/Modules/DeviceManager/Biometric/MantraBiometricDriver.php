<?php

declare(strict_types=1);

namespace App\Modules\DeviceManager\Biometric;

use App\Modules\DeviceManager\Data\PunchRecord;

/**
 * Mantra terminals (MFSTAB, mfs100 desktop terminal, MIRA100 face unit).
 *
 * Mantra's local service posts camelCase JSON — `{userId, punchDateTime,
 * direction}` — with direction already spelled out as "IN"/"OUT", and it names
 * the biometric channel in `deviceType` (FP for finger, FACE for the MIRA units).
 */
class MantraBiometricDriver extends AbstractBiometricDriver
{
    protected function logRows(array $payload): array
    {
        return $payload['punchData'] ?? $payload['data'] ?? $payload['logs'] ?? [];
    }

    /** @return PunchRecord[] */
    public function parseLogs(array $rows): array
    {
        $punches = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $userId = trim((string) ($row['userId'] ?? $row['employeeId'] ?? ''));
            $at = $this->parseTime($row['punchDateTime'] ?? $row['punchTime'] ?? null);
            if ($userId === '' || $at === null) {
                continue;
            }

            $direction = strtolower((string) ($row['direction'] ?? 'in'));

            $punches[] = new PunchRecord(
                deviceUserId: $userId,
                punchedAt: $at,
                direction: str_starts_with($direction, 'o') ? 'out' : 'in',
                verifyMode: match (strtoupper((string) ($row['deviceType'] ?? 'FP'))) {
                    'FACE' => 'face',
                    'CARD' => 'card',
                    default => 'fingerprint',
                },
            );
        }

        return $punches;
    }

    protected function buildRows(array $punches): array
    {
        return array_map(fn (array $p) => [
            'userId' => $p['device_user_id'],
            'punchDateTime' => $p['punched_at']->toIso8601String(),
            'direction' => strtoupper($p['direction']),
            'deviceType' => 'FP',
        ], $punches);
    }
}
