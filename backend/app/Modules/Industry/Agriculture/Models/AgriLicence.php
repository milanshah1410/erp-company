<?php

declare(strict_types=1);

namespace App\Modules\Industry\Agriculture\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;

/**
 * A dealer's licence to trade a controlled agricultural input.
 *
 * The record exists for one reason: the date. A seed, fertiliser or insecticide
 * licence lapses quietly, usually mid-season when nobody is looking at
 * paperwork, and selling a day past it is an offence rather than an oversight.
 * Everything else here is what has to be reproduced on demand for an inspector.
 */
class AgriLicence extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    /** How far ahead a licence counts as "expiring soon" on the register. */
    public const EXPIRY_WARNING_DAYS = 60;

    protected $fillable = [
        'licence_type', 'licence_number', 'form_number', 'authority',
        'holder_name', 'premises', 'issued_on', 'valid_till',
        'status', 'remarks', 'created_by',
    ];

    protected $casts = [
        'issued_on' => 'date',
        'valid_till' => 'date',
    ];

    /**
     * Days until this licence lapses — negative once it has. Null means the
     * licence carries no expiry at all, which is a real (if rare) case and is
     * deliberately distinguished from "expires today".
     */
    public function daysToExpiry(): ?int
    {
        if (! $this->valid_till) {
            return null;
        }

        return (int) now()->startOfDay()->diffInDays($this->valid_till->startOfDay(), false);
    }

    /**
     * The state to act on, which is not the same as the stored `status`.
     *
     * Expiry is derived rather than stored: a stored 'expired' would be wrong
     * from the moment it was written until a nightly job caught up, and this is
     * exactly the field nobody can afford to have stale.
     */
    public function effectiveStatus(): string
    {
        if ($this->status !== 'active') {
            return $this->status;
        }

        $days = $this->daysToExpiry();

        return match (true) {
            $days === null => 'active',
            $days < 0 => 'expired',
            $days <= self::EXPIRY_WARNING_DAYS => 'expiring',
            default => 'active',
        };
    }

    /** Can the shop legally sell this input type today? */
    public function isValidToday(): bool
    {
        return in_array($this->effectiveStatus(), ['active', 'expiring'], true);
    }
}
