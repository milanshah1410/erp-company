<?php

declare(strict_types=1);

namespace App\Modules\DeviceManager\Data;

use Illuminate\Support\Carbon;

/**
 * One attendance punch pulled from a biometric terminal, normalised to the
 * shape App\Models\BiometricPunch stores (device_user_id + timestamp +
 * direction), whatever field names the brand's own log used.
 */
class PunchRecord
{
    public function __construct(
        public readonly string $deviceUserId,
        public readonly Carbon $punchedAt,
        public readonly string $direction = 'in',
        /** fingerprint | face | card | password — how the person verified. */
        public readonly ?string $verifyMode = null,
        public readonly bool $simulated = false,
    ) {}

    public function toArray(): array
    {
        return [
            'device_user_id' => $this->deviceUserId,
            'punched_at' => $this->punchedAt->toDateTimeString(),
            'direction' => $this->direction,
            'verify_mode' => $this->verifyMode,
            'simulated' => $this->simulated,
        ];
    }
}
