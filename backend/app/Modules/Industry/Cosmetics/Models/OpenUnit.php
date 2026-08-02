<?php

declare(strict_types=1);

namespace App\Modules\Industry\Cosmetics\Models;

use App\Models\Batch;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One unit whose seal has been broken — nearly always a tester on the counter.
 *
 * This is the row core inventory has no shape for. Stock is either on a shelf or
 * sold; a tester is neither. It has left saleable stock (so it is written off
 * the moment it is opened), it will never be billed, and it dies on a date that
 * exists nowhere on the pack: opened_on plus the period after opening, the "6M"
 * in the little open-jar symbol.
 *
 * That date is the point of the register. A mascara tester used by strangers for
 * eight months is an eye infection and a shop's reputation, and nothing about it
 * looks any different on day two hundred and forty than it did on day one — the
 * carton's printed expiry may still be two years away. Somebody has to be told,
 * and the only way to tell them is to have written down the day it was opened.
 *
 * Both dates are snapshotted at opening (see the migration): a buyer correcting
 * the product's PAO next month must not silently move the death date of a tester
 * that has been in use since March.
 */
class OpenUnit extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    protected $table = 'cosmetic_open_units';

    protected $fillable = [
        'product_id', 'variant_id', 'batch_id', 'warehouse_id',
        'purpose', 'quantity', 'opened_on', 'pao_months', 'expires_on',
        'status', 'closed_on', 'opened_by', 'notes',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'opened_on' => 'date',
        'expires_on' => 'date',
        'closed_on' => 'date',
        'pao_months' => 'integer',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'purpose' => 'tester',
        'status' => 'open',
        'quantity' => 1,
    ];

    public const PURPOSES = ['tester', 'demo', 'staff', 'quality'];

    public const STATUSES = ['open', 'finished', 'discarded'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', 'open');
    }

    /**
     * Open units already past their PAO date.
     *
     * Note there is no 'expired' status to filter on, deliberately. Expiry is
     * not something that happens to the row — nobody comes in and marks it — it
     * is simply a date going past while the unit sits there, and a status column
     * claiming otherwise would be wrong every day until a job got around to
     * fixing it. The board derives it; closing the unit is the human act, and
     * that is what `discarded` records.
     */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query->where('status', 'open')
            ->whereNotNull('expires_on')
            ->whereDate('expires_on', '<', now()->toDateString());
    }

    /** Days left on the clock; negative once it has run out, null with no PAO. */
    public function daysLeft(): ?int
    {
        if (! $this->expires_on) {
            return null;
        }

        return (int) now()->startOfDay()->diffInDays($this->expires_on->copy()->startOfDay(), false);
    }

    public function isOverdue(): bool
    {
        $days = $this->daysLeft();

        return $this->status === 'open' && $days !== null && $days < 0;
    }

    /** The date a unit opened today on this PAO would run out. */
    public static function expiryFrom(?string $openedOn, ?int $paoMonths): ?string
    {
        if (! $paoMonths || $paoMonths < 1) {
            return null;
        }

        return Carbon::parse($openedOn ?: now()->toDateString())->addMonths($paoMonths)->toDateString();
    }
}
