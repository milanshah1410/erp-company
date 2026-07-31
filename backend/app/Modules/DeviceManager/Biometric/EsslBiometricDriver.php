<?php

declare(strict_types=1);

namespace App\Modules\DeviceManager\Biometric;

use App\Modules\DeviceManager\Data\PunchRecord;

/**
 * eSSL terminals (X990, K90) and Biomax N-BM10.
 *
 * eSSL's eTimeTrack/ADMS export uses PascalCase keys and its own in/out flag:
 * `{EmployeeCode, PunchTime, InOutMode}` where InOutMode 1 = in and 2 = out.
 * Payloads identify the terminal by serial number rather than IP, so the serial
 * is part of the configuration.
 */
class EsslBiometricDriver extends AbstractBiometricDriver
{
    private const VERIFY_MODES = [
        1 => 'fingerprint',
        2 => 'card',
        3 => 'password',
        4 => 'face',
    ];

    protected function logRows(array $payload): array
    {
        return $payload['PunchData'] ?? $payload['data'] ?? $payload['punches'] ?? [];
    }

    /** @return PunchRecord[] */
    public function parseLogs(array $rows): array
    {
        $punches = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $code = trim((string) ($row['EmployeeCode'] ?? $row['UserId'] ?? $row['pin'] ?? ''));
            $at = $this->parseTime($row['PunchTime'] ?? $row['punch_time'] ?? null);
            if ($code === '' || $at === null) {
                continue;
            }

            $mode = (int) ($row['InOutMode'] ?? 1);

            $punches[] = new PunchRecord(
                deviceUserId: $code,
                punchedAt: $at,
                direction: $mode === 2 ? 'out' : 'in',
                verifyMode: self::VERIFY_MODES[(int) ($row['VerifyMode'] ?? 1)] ?? null,
            );
        }

        return $punches;
    }

    protected function buildRows(array $punches): array
    {
        return array_map(fn (array $p) => [
            'EmployeeCode' => $p['device_user_id'],
            'PunchTime' => $p['punched_at']->format('Y-m-d H:i:s'),
            'InOutMode' => $p['direction'] === 'out' ? 2 : 1,
            'VerifyMode' => 1,
            'SerialNumber' => (string) $this->setting('serial_no', ''),
        ], $punches);
    }
}
