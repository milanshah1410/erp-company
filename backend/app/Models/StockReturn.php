<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasDocumentNumber;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockReturn extends Model
{
    use BelongsToTenant, HasDocumentNumber, LogsAuditActivity;

    protected static string $numberSettingKey = 'return_number_prefix';

    /**
     * The debit note's own series (retail base §3), separate from
     * `return_number_prefix`. A vendor files the note against its own sales
     * ledger and quotes the number back when it issues credit, so it needs a
     * short series of its own rather than sharing the internal return's.
     */
    public const DEBIT_NOTE_SETTING_KEY = 'debit_note_number_prefix';

    protected $fillable = [
        'return_number', 'warehouse_id', 'return_type', 'customer_id', 'supplier_id', 'goods_receipt_id',
        'debit_note_number', 'debit_note_date', 'debit_note_tax_percent',
        'debit_note_issued_at', 'debit_note_issued_by',
        'status', 'return_date', 'notes', 'created_by', 'approved_by', 'approved_at',
    ];

    protected $casts = [
        'return_date' => 'date',
        'approved_at' => 'datetime',
        'debit_note_date' => 'date',
        'debit_note_tax_percent' => 'decimal:2',
        'debit_note_issued_at' => 'datetime',
    ];

    /**
     * Next number in the debit-note series.
     *
     * Counts issued notes rather than reusing HasDocumentNumber::nextNumber(),
     * which pads the table's own id: most returns are never turned into a note,
     * so an id-derived series would hand a vendor DN-000412 as the shop's
     * fourteenth claim, and the gaps would read like withdrawn documents.
     */
    public static function nextDebitNoteNumber(): string
    {
        $prefix = Setting::numberPrefix(self::DEBIT_NOTE_SETTING_KEY);
        $issued = static::query()->whereNotNull('debit_note_issued_at')->count();

        return $prefix.'-'.str_pad((string) ($issued + 1), 6, '0', STR_PAD_LEFT);
    }

    /** Goods value being claimed back, before tax. */
    public function debitNoteSubtotal(): float
    {
        return round($this->items->sum(fn (StockReturnItem $i) => (float) $i->quantity * (float) $i->unit_cost), 2);
    }

    public function debitNoteTaxAmount(): float
    {
        return round($this->debitNoteSubtotal() * (float) $this->debit_note_tax_percent / 100, 2);
    }

    /** What the supplier is being asked to credit, tax included. */
    public function debitNoteTotal(): float
    {
        return round($this->debitNoteSubtotal() + $this->debitNoteTaxAmount(), 2);
    }

    /**
     * A note may only be raised against goods that have actually gone back to
     * the vendor. Claiming credit for stock still sitting on the shelf is the
     * one mistake this document can make that costs a supplier relationship.
     */
    public function canIssueDebitNote(): bool
    {
        return $this->return_type === 'vendor_return'
            && $this->status === 'approved'
            && $this->debit_note_issued_at === null;
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockReturnItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function debitNoteIssuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'debit_note_issued_by');
    }
}
