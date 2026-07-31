<?php

declare(strict_types=1);

namespace App\Modules\DeviceManager\Biometric;

use App\Modules\DeviceManager\Data\PunchRecord;

/**
 * Realtime terminals (T52, T5, Eco S C121).
 *
 * Realtime exports split date and time into separate columns — `{UserID,
 * LogDate, LogTime, C1}` — and use the C1 flag for in/out, where an empty C1
 * means the terminal was not configured for direction at all. In that case the
 * punch is recorded as 'in' and the HR attendance roll-up decides in/out from
 * the earliest and latest punch of the day, which is how BiometricPunchController
 * already works.
 */
class RealtimeBiometricDriver extends AbstractBiometricDriver
{
    protected function logRows(array $payload): array
    {
        return $payload['Logs'] ?? $payload['data'] ?? $payload['logs'] ?? [];
    }

    /** @return PunchRecord[] */
    public function parseLogs(array $rows): array
    {
        $punches = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $userId = trim((string) ($row['UserID'] ?? $row['UserId'] ?? ''));
            $date = trim((string) ($row['LogDate'] ?? ''));
            $time = trim((string) ($row['LogTime'] ?? ''));

            $at = $this->parseTime(trim($date . ' ' . $time));
            if ($userId === '' || $at === null) {
                continue;
            }

            $flag = strtoupper(trim((string) ($row['C1'] ?? '')));

            $punches[] = new PunchRecord(
                deviceUserId: $userId,
                punchedAt: $at,
                direction: in_array($flag, ['OUT', 'O', '1'], true) ? 'out' : 'in',
                verifyMode: 'fingerprint',
            );
        }

        return $punches;
    }

    protected function buildRows(array $punches): array
    {
        return array_map(fn (array $p) => [
            'UserID' => $p['device_user_id'],
            'LogDate' => $p['punched_at']->format('Y-m-d'),
            'LogTime' => $p['punched_at']->format('H:i:s'),
            'C1' => $p['direction'] === 'out' ? 'OUT' : 'IN',
        ], $punches);
    }
}
