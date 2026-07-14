<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasDocumentNumber;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A company operating expense booked against a category (and optionally a
 * branch). Runs through a light approval workflow — draft → pending →
 * approved / rejected — and is flagged paid once settled. May carry a
 * scanned bill/receipt attachment.
 */
class Expense extends Model
{
    use BelongsToTenant, HasDocumentNumber, LogsAuditActivity;

    protected static string $numberSettingKey = 'expense_number_prefix';

    protected $fillable = [
        'expense_number', 'expense_category_id', 'branch_id', 'expense_date',
        'amount', 'tds_section', 'tds_rate', 'tds_amount',
        'payment_method', 'payee', 'reference_no', 'description',
        'status', 'attachment_path', 'paid_at', 'rejection_reason',
        'created_by', 'approved_by', 'approved_at',
    ];

    protected $casts = [
        'expense_date' => 'date',
        'amount' => 'decimal:2',
        'tds_rate' => 'decimal:2',
        'tds_amount' => 'decimal:2',
        'paid_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
