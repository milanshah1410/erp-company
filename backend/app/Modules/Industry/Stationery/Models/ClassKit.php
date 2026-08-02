<?php

declare(strict_types=1);

namespace App\Modules\Industry\Stationery\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasDocumentNumber;
use App\Models\Concerns\LogsAuditActivity;
use App\Models\SalesOrder;
use App\Models\SalesQuotation;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One class's booklist — the list a school publishes and a shop supplies against.
 *
 * This is the record the whole vertical exists for. Between April and June a
 * stationery shop's entire job is turning "Class 5, Sunrise School" into
 * twenty-two priced lines, twice a minute, with three of them out of stock. Every
 * other system makes someone type that list; here it is a document.
 *
 * Deliberately not a `Bom`. A bill of materials is consumed to produce a
 * manufactured good and is driven by a manufacturing order — nothing about it
 * survives contact with this trade. A kit produces nothing, it *sells*; its lines
 * are routinely optional; and it has to price differently for the school than for
 * the parent. The two look alike only in the sense that both are lists.
 *
 * The status is load-bearing rather than decorative. A half-entered booklist is
 * indistinguishable from a finished one at a glance, so only a published kit can
 * be sold from — otherwise the counter cheerfully sells the eleven items somebody
 * had keyed in before lunch and the parent goes home short.
 */
class ClassKit extends Model
{
    use BelongsToTenant, HasDocumentNumber, LogsAuditActivity;

    protected $table = 'stationery_class_kits';

    protected static string $numberSettingKey = 'stationery_kit_number_prefix';

    protected $fillable = [
        'kit_number', 'institution_id', 'academic_year', 'class_label', 'stream',
        'title', 'status', 'published_at', 'discount_percent', 'is_mandatory_set',
        'expected_students', 'notes', 'created_by',
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'discount_percent' => 'decimal:2',
        'is_mandatory_set' => 'boolean',
        'expected_students' => 'integer',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'draft',
        'discount_percent' => 0,
        'is_mandatory_set' => false,
    ];

    /** Null for a shop's own generic list, which is a real kit and not a draft. */
    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ClassKitItem::class, 'kit_id')->orderBy('sort_order')->orderBy('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Orders raised from this booklist — ordinary sales orders, stamped. */
    public function salesOrders(): HasMany
    {
        return $this->hasMany(SalesOrder::class, 'stn_class_kit_id');
    }

    public function quotations(): HasMany
    {
        return $this->hasMany(SalesQuotation::class, 'stn_class_kit_id');
    }

    /** "Class 5 · Science — Sunrise English School (2026-27)". */
    public function label(): string
    {
        $head = trim($this->class_label.($this->stream ? " · {$this->stream}" : ''));
        $school = $this->institution?->name ?? 'Generic list';

        return "{$head} — {$school} ({$this->academic_year})";
    }

    /** Only a published list may be sold from. */
    public function isSellable(): bool
    {
        return $this->status === 'published';
    }

    /**
     * Release the list to the counter.
     *
     * Refuses an empty one, which is the whole reason the state exists: an empty
     * published kit is a promise that a parent can be served, and it cannot be.
     */
    public function publish(): bool
    {
        if ($this->status === 'archived' || ! $this->items()->exists()) {
            return false;
        }

        $this->forceFill(['status' => 'published', 'published_at' => now()])->save();

        return true;
    }

    /**
     * Lines a buyer actually has to take.
     *
     * A school that mandates the whole set makes its optional lines compulsory in
     * practice, which is why the flag is read here rather than at the line: the
     * counter needs one answer, and it depends on the school.
     */
    public function requiredItems()
    {
        $this->loadMissing('items');

        return $this->is_mandatory_set
            ? $this->items
            : $this->items->where('is_optional', false);
    }
}
