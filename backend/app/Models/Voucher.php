<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use App\Models\Setting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A manual accounting voucher. Four types feed the derived books:
 *
 *  - receipt: money IN to bank_account_id (from a party)          -> Cash/Bank Book debit
 *  - payment: money OUT of bank_account_id (to a party)           -> Cash/Bank Book credit
 *  - contra:  transfer counter_account_id -> bank_account_id      -> both books (out + in)
 *  - journal: non-cash adjustment (debit_ledger / credit_ledger)  -> Journal book only
 *
 * Cancelled vouchers are excluded from every book.
 */
class Voucher extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    /** Per-type document-number prefix setting keys. */
    private const PREFIX_KEYS = [
        'receipt' => 'receipt_voucher_number_prefix',
        'payment' => 'payment_voucher_number_prefix',
        'contra' => 'contra_voucher_number_prefix',
        'journal' => 'journal_voucher_number_prefix',
    ];

    protected $fillable = [
        'voucher_number', 'voucher_type', 'voucher_date', 'amount', 'mode',
        'bank_account_id', 'counter_account_id', 'debit_ledger', 'credit_ledger',
        'party_type', 'party_id', 'party_name', 'reference_no', 'narration',
        'branch_id', 'status', 'created_by',
    ];

    protected $casts = [
        'voucher_date' => 'date',
        'amount' => 'decimal:2',
    ];

    /**
     * Sequential voucher number with a per-type prefix (RV / PV / CV / JV).
     * Mirrors HasDocumentNumber but keyed on the voucher type.
     */
    public static function nextNumber(string $type): string
    {
        $last = static::query()->orderByDesc('id')->value('id') ?? 0;
        $prefix = Setting::numberPrefix(self::PREFIX_KEYS[$type] ?? 'journal_voucher_number_prefix');

        return $prefix.'-'.str_pad((string) ($last + 1), 6, '0', STR_PAD_LEFT);
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function counterAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'counter_account_id');
    }

    public function party(): MorphTo
    {
        return $this->morphTo();
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
