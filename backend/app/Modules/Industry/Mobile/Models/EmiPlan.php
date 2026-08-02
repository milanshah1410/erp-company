<?php

declare(strict_types=1);

namespace App\Modules\Industry\Mobile\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasDocumentNumber;
use App\Models\Concerns\LogsAuditActivity;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\User;
use App\Modules\Industry\Mobile\Support\Imei;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * The finance file behind a handset sale.
 *
 * Most phones above a certain price leave this trade on EMI, and the shop is asked
 * about the file long after the sale — "how many are left?", "what is my due
 * date?", "the bank is calling me". So the file is kept even when the money is none
 * of the shop's business.
 *
 * Whether it *is* the shop's business is the one thing this model turns on:
 *
 *  - `finance_company` — Bajaj, an HDFC EMI card, a bank. The financier settles
 *    with the shop at disbursal, so the invoice is paid in full on day one and the
 *    schedule here is a courtesy. Collecting an instalment must never write a
 *    payment: the money is not coming to us, and banking it twice is how a cash
 *    book stops reconciling.
 *  - `in_house` — the shop financed it. Every pending instalment is a receivable
 *    and collecting one is real money against the real invoice.
 *
 * isReceivable() is the single place that distinction is expressed.
 */
class EmiPlan extends Model
{
    use BelongsToTenant, HasDocumentNumber, LogsAuditActivity;

    protected $table = 'mobile_emi_plans';

    protected static string $numberSettingKey = 'emi_plan_number_prefix';

    protected $fillable = [
        'plan_number', 'customer_id', 'financier', 'financier_name', 'loan_account_no',
        'invoice_id', 'product_id', 'handset_id', 'imei',
        'asset_value', 'down_payment', 'financed_amount', 'processing_fee', 'interest_percent',
        'tenure_months', 'instalment_amount', 'first_due_date',
        'status', 'closed_on', 'notes', 'created_by',
    ];

    protected $casts = [
        'asset_value' => 'decimal:2',
        'down_payment' => 'decimal:2',
        'financed_amount' => 'decimal:2',
        'processing_fee' => 'decimal:2',
        'interest_percent' => 'decimal:3',
        'tenure_months' => 'integer',
        'instalment_amount' => 'decimal:2',
        'first_due_date' => 'date',
        'closed_on' => 'date',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'financier' => 'in_house',
        'status' => 'active',
        'asset_value' => 0,
        'down_payment' => 0,
        'financed_amount' => 0,
        'processing_fee' => 0,
        'interest_percent' => 0,
        'instalment_amount' => 0,
    ];

    protected static function booted(): void
    {
        static::saving(function (self $plan) {
            $plan->imei = Imei::normalize($plan->imei);
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function handset(): BelongsTo
    {
        return $this->belongsTo(Handset::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function instalments(): HasMany
    {
        return $this->hasMany(EmiInstalment::class, 'emi_plan_id')->orderBy('sequence');
    }

    /**
     * Is the shop itself waiting for this money? The whole reason the `financier`
     * column exists — everything that touches the cash book asks this first.
     */
    public function isReceivable(): bool
    {
        return $this->financier === 'in_house';
    }

    /**
     * The schedule, read from the loaded relation when there is one.
     *
     * Every figure below is derived from the schedule, and a collections desk is
     * exactly the screen that shows a hundred files at once — four aggregate queries
     * per row would be four hundred queries to draw one page. So callers eager-load
     * `instalments` and these read it in memory; the fallback keeps a single-model
     * call working.
     *
     * @return Collection<int, EmiInstalment>
     */
    private function schedule(): Collection
    {
        return $this->relationLoaded('instalments')
            ? $this->instalments
            : $this->instalments()->get();
    }

    /** Instalments settled so far. */
    public function paidCount(): int
    {
        return $this->schedule()->whereIn('status', ['paid', 'waived'])->count();
    }

    /** Money still to come in on the schedule. */
    public function outstanding(): float
    {
        return round((float) $this->schedule()->where('status', 'pending')->sum('amount'), 2);
    }

    /**
     * Instalments past their date and still unpaid.
     *
     * Computed, never stored: an instalment becomes overdue at midnight with nobody
     * there to write it down, and a stored flag would need a nightly sweep to stay
     * honest.
     */
    public function overdueCount(): int
    {
        return $this->schedule()->filter(fn (EmiInstalment $i) => $i->isOverdue())->count();
    }

    /** The next thing falling due, or null on a finished file. */
    public function nextDue(): ?EmiInstalment
    {
        return $this->schedule()
            ->where('status', 'pending')
            ->sortBy('due_date')
            ->first();
    }

    /**
     * Close the file if nothing is left pending. Called after every collection so a
     * fully paid file does not sit there looking active — which is what makes a
     * collections list trustworthy enough to work from.
     *
     * Queries rather than reading the loaded relation on purpose: this runs
     * immediately after an instalment was saved, and an eager-loaded collection is
     * stale by exactly the row that just changed.
     */
    public function closeIfSettled(): void
    {
        if ($this->status !== 'active') {
            return;
        }

        if ($this->instalments()->where('status', 'pending')->exists()) {
            return;
        }

        $this->forceFill(['status' => 'closed', 'closed_on' => now()->toDateString()])->save();
    }
}
