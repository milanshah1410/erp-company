<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use App\Support\TradeCatalogue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A logistics cost booked against a shipment (SRS §24) — freight, THC, CHA
 * charges, duty, inland transport, detention, bank charges and so on.
 *
 * `amount` is in the expense's own `currency` (a forwarder may bill in USD while
 * the CHA bills in INR); `base_amount` is the converted figure and the only one
 * roll-ups touch — it is recomputed on every write, never trusted from the
 * client. `is_billable` marks a cost recovered from the counterparty, which the
 * Shipment Profit board therefore leaves out of cost.
 */
class ShipmentExpense extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    /**
     * Who can bill a logistics cost: any of the module's service-provider
     * masters, an ordinary supplier, or nobody in particular (free-text vendor).
     * Stored as vendor_type + vendor_id because it spans tables, with the
     * resolved name denormalised into vendor_name for display and export.
     *
     * @var array<string, class-string<\Illuminate\Database\Eloquent\Model>>
     */
    public const VENDOR_MODELS = [
        'freight_forwarder' => FreightForwarder::class,
        'cha_agent' => ChaAgent::class,
        'transporter' => Transporter::class,
        'shipping_line' => ShippingLine::class,
        'airline' => Airline::class,
        'supplier' => Supplier::class,
    ];

    protected $fillable = [
        'shipment_id', 'shipment_container_id', 'expense_type', 'expense_date',
        'vendor_type', 'vendor_id', 'vendor_name',
        'invoice_number', 'currency', 'exchange_rate', 'amount', 'base_amount',
        'payment_status', 'paid_amount', 'due_date', 'is_billable', 'remarks', 'created_by',
    ];

    protected $casts = [
        'expense_date' => 'date',
        'due_date' => 'date',
        'exchange_rate' => 'decimal:6',
        'amount' => 'decimal:2',
        'base_amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'is_billable' => 'boolean',
    ];

    /**
     * Keep the derived columns consistent with whatever the caller set, so no
     * write path can leave the money columns disagreeing or the vendor unnamed.
     */
    protected static function booted(): void
    {
        static::saving(function (self $expense): void {
            $expense->base_amount = round((float) $expense->amount * (float) $expense->exchange_rate, 2);

            $paid = (float) $expense->paid_amount;
            $expense->payment_status = match (true) {
                $paid <= 0 => 'unpaid',
                $paid + 0.01 >= (float) $expense->base_amount => 'paid',
                default => 'partial',
            };

            if ($expense->vendor_type && $expense->vendor_id) {
                $expense->vendor_name = $expense->resolveVendorName() ?? $expense->vendor_name;
            }
        });
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    /** The box this cost belongs to — set for per-container heads (detention, demurrage). */
    public function container(): BelongsTo
    {
        return $this->belongsTo(ShipmentContainer::class, 'shipment_container_id');
    }

    /** The vendor master this cost is billed by, or null for a free-text vendor. */
    public function vendor(): ?Model
    {
        $model = self::VENDOR_MODELS[(string) $this->vendor_type] ?? null;

        return $model && $this->vendor_id ? $model::find($this->vendor_id) : null;
    }

    /** Look the vendor's name up on its master, for the denormalised column. */
    private function resolveVendorName(): ?string
    {
        return $this->vendor()?->name;
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Expenses with money still owed to the vendor. */
    public function scopeUnsettled(Builder $query): Builder
    {
        return $query->whereIn('payment_status', ['unpaid', 'partial']);
    }

    public function label(): string
    {
        return TradeCatalogue::expenseLabel($this->expense_type);
    }

    /** Still owed on this expense, base currency. */
    public function outstandingAmount(): float
    {
        return round(max(0, (float) $this->base_amount - (float) $this->paid_amount), 2);
    }
}
