<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One promise about commission (retail base §8b): who earns what, on what.
 *
 * Matching lives here rather than in the service so that the rule can answer
 * "does this apply?" about itself — which is what the rules screen needs in
 * order to preview a rule against a real sale before anyone is paid under it.
 */
class CommissionRule extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    public const BASES = ['percent_of_sale', 'percent_of_margin', 'flat_per_unit'];

    protected $fillable = [
        'name', 'applies_to', 'employee_id', 'department_id',
        'category_id', 'brand_id', 'product_id',
        'basis', 'rate', 'min_line_amount', 'min_invoice_amount',
        'priority', 'effective_from', 'effective_to', 'is_active', 'notes', 'created_by',
    ];

    protected $casts = [
        'rate' => 'decimal:4',
        'min_line_amount' => 'decimal:2',
        'min_invoice_amount' => 'decimal:2',
        'priority' => 'integer',
        'effective_from' => 'date',
        'effective_to' => 'date',
        'is_active' => 'boolean',
    ];

    /** In force on a given date. An open-ended rule is in force from the start. */
    public function isEffectiveOn(string $date): bool
    {
        if (! $this->is_active) {
            return false;
        }
        if ($this->effective_from && $date < $this->effective_from->toDateString()) {
            return false;
        }
        if ($this->effective_to && $date > $this->effective_to->toDateString()) {
            return false;
        }

        return true;
    }

    /**
     * Does this rule cover this seller?
     *
     * A rule scoped to a department matches anybody in it, which is why the
     * employee's department is passed in rather than re-queried per line.
     */
    public function coversSeller(int $employeeId, ?int $departmentId): bool
    {
        return match ($this->applies_to) {
            'employee' => $this->employee_id === $employeeId,
            'department' => $this->department_id !== null && $this->department_id === $departmentId,
            default => true,
        };
    }

    /**
     * Does this rule cover this article?
     *
     * Each of the three product filters is an AND when set and ignored when
     * null, so "Brand X in the Footwear category" is sayable and so is "anything".
     */
    public function coversProduct(?int $productId, ?int $categoryId, ?int $brandId): bool
    {
        if ($this->product_id !== null && $this->product_id !== $productId) {
            return false;
        }
        if ($this->category_id !== null && $this->category_id !== $categoryId) {
            return false;
        }
        if ($this->brand_id !== null && $this->brand_id !== $brandId) {
            return false;
        }

        return true;
    }

    /**
     * What this rule pays on one line.
     *
     * $margin is null where no cost was posted for the line. A margin-based rule
     * then pays nothing rather than treating the whole sale value as profit,
     * which would be the expensive kind of wrong.
     */
    public function commissionFor(float $quantity, float $lineValue, ?float $margin): float
    {
        /**
         * Kept as the rule's own invariant even though SalesCommissionService
         * already treats the floor as a *matching* condition and never offers
         * this rule a line below it. Belt and braces for any other caller — but
         * note the two are not interchangeable: skipping the rule lets a lower
         * one pay the line, whereas returning 0.0 here would not.
         */
        if ($lineValue + 0.005 < (float) $this->min_line_amount) {
            return 0.0;
        }

        return match ($this->basis) {
            'percent_of_sale' => round($lineValue * (float) $this->rate / 100, 2),
            'percent_of_margin' => $margin === null ? 0.0 : round(max($margin, 0) * (float) $this->rate / 100, 2),
            'flat_per_unit' => round($quantity * (float) $this->rate, 2),
            default => 0.0,
        };
    }

    /**
     * Human-readable scope, for the rules list — "All staff · Brand X".
     *
     * Named `describeScope`, not `scopeSummary`: Eloquent reads any method
     * prefixed with `scope` as a query scope, so the obvious name would have
     * registered a local scope called `summary()` and never been callable.
     */
    public function describeScope(): string
    {
        $parts = [];
        $parts[] = match ($this->applies_to) {
            'employee' => $this->employee?->fullName() ?: 'One employee',
            'department' => $this->department?->name ?? 'One department',
            default => 'All staff',
        };
        if ($this->product) {
            $parts[] = $this->product->name;
        }
        if ($this->category) {
            $parts[] = $this->category->name;
        }
        if ($this->brand) {
            $parts[] = $this->brand->name;
        }
        if (count($parts) === 1) {
            $parts[] = 'anything sold';
        }

        return implode(' · ', $parts);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function earnings(): HasMany
    {
        return $this->hasMany(CommissionEarning::class);
    }
}
