<?php

declare(strict_types=1);

namespace App\Modules\Industry\Stationery\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasDocumentNumber;
use App\Models\Concerns\LogsAuditActivity;
use App\Models\Customer;
use App\Models\SalesOrder;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A school, college or office the shop supplies — the account the whole season
 * hangs off.
 *
 * It is deliberately not a Customer, and the distinction is the point rather
 * than a technicality. A customer is a ledger party: someone the shop bills and
 * collects from. An institution is a *catalogue owner*: it publishes lists,
 * appoints suppliers and negotiates what parents pay, and in most of these
 * relationships it never owes the shop a rupee — four hundred separate parents
 * do, at the counter, one at a time. Modelling the school as a customer would
 * mean either inventing a debtor who never buys anything or losing the school
 * entirely, and neither leaves anywhere to put the booklist.
 *
 * Where the school *is* also a buyer — five hundred diaries, exam paper by the
 * ream — `customer_id` points at the ledger party, which is routinely a trust
 * with a different name on it.
 */
class Institution extends Model
{
    use BelongsToTenant, HasDocumentNumber, LogsAuditActivity;

    protected $table = 'stationery_institutions';

    /** Feeds nextNumber(); the code is generated, never typed. */
    protected static string $numberSettingKey = 'stationery_institution_code_prefix';

    protected $fillable = [
        'code', 'name', 'customer_id', 'type', 'board', 'medium',
        'academic_year', 'session_starts_on', 'student_count',
        'contact_person', 'contact_phone', 'contact_email', 'address',
        'is_authorised_vendor', 'parent_discount_percent', 'school_discount_percent',
        'credit_days', 'requires_po', 'notes', 'is_active', 'created_by',
    ];

    protected $casts = [
        'session_starts_on' => 'date',
        'student_count' => 'integer',
        'is_authorised_vendor' => 'boolean',
        'parent_discount_percent' => 'decimal:2',
        'school_discount_percent' => 'decimal:2',
        'credit_days' => 'integer',
        'requires_po' => 'boolean',
        'is_active' => 'boolean',
    ];

    /**
     * Mirrors of the column defaults, so a freshly created instance reads the
     * same as a reloaded one — the discount accessors below are consulted while
     * pricing a kit, sometimes against a record that has not been round-tripped.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'type' => 'school',
        'is_authorised_vendor' => false,
        'parent_discount_percent' => 0,
        'school_discount_percent' => 0,
        'requires_po' => false,
        'is_active' => true,
    ];

    /** The ledger party the institution's own purchases bill to, if any. */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function kits(): HasMany
    {
        return $this->hasMany(ClassKit::class, 'institution_id');
    }

    /**
     * The school's own orders — sales orders, on the institution channel.
     *
     * Declared here rather than on SalesOrder so the dependency points from the
     * plugin at the core and never back; the reverse relation is registered at
     * boot by StationeryPlugin.
     */
    public function salesOrders(): HasMany
    {
        return $this->hasMany(SalesOrder::class, 'stn_institution_id');
    }

    /** "SCH-000004 — Sunrise English School (CBSE)" for pickers and list headers. */
    public function label(): string
    {
        $suffix = $this->board ? " ({$this->board})" : '';

        return "{$this->name}{$suffix}";
    }

    /**
     * The concession that applies to one buyer of this school's list.
     *
     * Two rates exist because there are two buyers, and picking the wrong one is
     * expensive in both directions: giving a parent the institution's bulk rate
     * gives away margin on every one of four hundred sets, and billing the school
     * at the parent rate loses the tender.
     */
    public function discountFor(bool $buyerIsTheInstitution): float
    {
        return (float) ($buyerIsTheInstitution
            ? $this->school_discount_percent
            : $this->parent_discount_percent);
    }
}
