<?php

declare(strict_types=1);

namespace App\Modules\DeviceManager\Biometric;

use App\Modules\DeviceManager\Data\PunchRecord;

/**
 * ZKTeco terminals (K40, F18, iClock 680, MB460).
 *
 * Stock firmware logs are `{pin, time, status, verify}`:
 *  - `pin`    the enrolment number, which maps to Employee::biometric_user_id
 *  - `status` 0/4 = check-in, 1/5 = check-out, 2/3 = break in/out
 *  - `verify` 1 = fingerprint, 2 = password, 3 = card, 15 = face
 *
 * The in-status codes are configurable because resellers reflash these with
 * their own status maps more often than one would like.
 */
class ZktecoBiometricDriver extends AbstractBiometricDriver
{
    private const VERIFY_MODES = [
        0 => 'password',
        1 => 'fingerprint',
        2 => 'password',
        3 => 'card',
        15 => 'face',
    ];

    protected function logRows(array $payload): array
    {
        return $payload['data'] ?? $payload['attendance'] ?? $payload['logs'] ?? [];
    }

    /** @return PunchRecord[] */
    public function parseLogs(array $rows): array
    {
        $inCodes = $this->inStatusCodes();
        $punches = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $pin = trim((string) ($row['pin'] ?? $row['userId'] ?? ''));
            $at = $this->parseTime($row['time'] ?? $row['timestamp'] ?? null);
            if ($pin === '' || $at === null) {
                continue;
            }

            $status = (string) ($row['status'] ?? '0');

            $punches[] = new PunchRecord(
                deviceUserId: $pin,
                punchedAt: $at,
                direction: in_array($status, $inCodes, true) ? 'in' : 'out',
                verifyMode: self::VERIFY_MODES[(int) ($row['verify'] ?? 1)] ?? null,
            );
        }

        return $punches;
    }

    protected function buildRows(array $punches): array
    {
        $inCode = $this->inStatusCodes()[0] ?? '0';

        return array_map(fn (array $p) => [
            'pin' => $p['device_user_id'],
            'time' => $p['punched_at']->toDateTimeString(),
            'status' => $p['direction'] === 'in' ? $inCode : '1',
            'verify' => 1,
        ], $punches);
    }

    /** @return string[] */
    private function inStatusCodes(): array
    {
        $configured = (string) $this->setting('in_status', '0');
        $codes = array_values(array_filter(array_map('trim', explode(',', $configured)), fn ($c) => $c !== ''));

        // 0 and 4 are both check-in on stock firmware.
        return $codes !== [] ? array_unique([...$codes, '4']) : ['0', '4'];
    }
}
