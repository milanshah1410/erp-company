<?php

declare(strict_types=1);

namespace App\Modules\Industry\Mobile\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasDocumentNumber;
use App\Models\Concerns\LogsAuditActivity;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Industry\Mobile\Support\Imei;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An old handset taken in part-exchange.
 *
 * Modelled on `old_gold_receipts`, because it is the same act in a different trade:
 * something of the customer's is valued, taken in, and held as a credit until it is
 * set off against a bill. And for the same reason it settles as a *tender* rather
 * than as a discount — the new phone is billed at the price on the shelf, and what
 * the shop allowed for the old one stays visible as its own line in the cash book
 * instead of vanishing into a discount percentage nobody can explain at month end.
 *
 * The states matter more than the fields:
 *
 *  - quoted    a valuation given over the counter. Most die here.
 *  - accepted  the customer handed the handset over. The shop is now holding their
 *              phone and owes them its value: an open credit.
 *  - settled   the credit was applied to an invoice.
 *  - rejected / expired  the quote came to nothing.
 *
 * Taking the handset into stock is deliberately not one of those states. It is an
 * orthogonal decision about a thing the shop already owns — grade A goes on the
 * refurbished shelf, grade D is spares — and until somebody makes it, the handset is
 * in a drawer, which is what the null stock columns mean.
 */
class Buyback extends Model
{
    use BelongsToTenant, HasDocumentNumber, LogsAuditActivity;

    protected $table = 'mobile_buybacks';

    protected static string $numberSettingKey = 'buyback_number_prefix';

    protected $fillable = [
        'buyback_number', 'customer_id',
        'brand', 'model_name', 'imei', 'device_serial', 'storage', 'colour', 'age_months',
        'grade', 'defects', 'has_box', 'has_charger', 'has_bill',
        'base_value', 'deduction', 'bonus_value', 'offered_value',
        'status', 'quoted_on', 'valid_until', 'accepted_on',
        'invoice_id', 'payment_id', 'settled_on',
        'stock_product_id', 'stock_warehouse_id', 'taken_into_stock_at',
        'notes', 'created_by',
    ];

    protected $casts = [
        'age_months' => 'integer',
        'has_box' => 'boolean',
        'has_charger' => 'boolean',
        'has_bill' => 'boolean',
        'base_value' => 'decimal:2',
        'deduction' => 'decimal:2',
        'bonus_value' => 'decimal:2',
        'offered_value' => 'decimal:2',
        'quoted_on' => 'date',
        'valid_until' => 'date',
        'accepted_on' => 'date',
        'settled_on' => 'date',
        'taken_into_stock_at' => 'datetime',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'grade' => 'B',
        'status' => 'quoted',
        'base_value' => 0,
        'deduction' => 0,
        'bonus_value' => 0,
        'offered_value' => 0,
        'has_box' => false,
        'has_charger' => false,
        'has_bill' => false,
    ];

    protected static function booted(): void
    {
        static::saving(function (self $buyback) {
            $buyback->imei = Imei::normalize($buyback->imei);

            /**
             * The offer is always recomputed from its parts, never trusted from the
             * request. The customer is shown this arithmetic at the counter, and a
             * stored total that disagrees with the figures beside it is an argument
             * the shop cannot win.
             */
            $buyback->offered_value = max(0, round(
                (float) $buyback->base_value
                - (float) $buyback->deduction
                + (float) $buyback->bonus_value,
                2,
            ));
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** The bill the credit was set off against. */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** The tender row the settlement wrote. */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** The catalogue line the handset was taken into stock as. */
    public function stockProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'stock_product_id');
    }

    public function stockWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'stock_warehouse_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Handsets the shop is holding whose value it still owes. */
    public function scopeOpenCredit(Builder $query): Builder
    {
        return $query->where('status', 'accepted')->whereNull('invoice_id');
    }

    public function isSettled(): bool
    {
        return $this->invoice_id !== null;
    }

    /** Is this quote still good today? Used-handset prices move weekly. */
    public function isLive(): bool
    {
        if ($this->status !== 'quoted') {
            return false;
        }

        return $this->valid_until === null || ! $this->valid_until->isPast();
    }

    public function isInStock(): bool
    {
        return $this->taken_into_stock_at !== null;
    }

    /** "Apple iPhone 12 · 128GB · Grade B" */
    public function label(): string
    {
        return trim(implode(' · ', array_filter([
            trim($this->brand.' '.$this->model_name),
            $this->storage,
            'Grade '.$this->grade,
        ])));
    }
}
