<?php

declare(strict_types=1);

namespace App\Modules\Industry\Healthcare\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use App\Models\Product;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;

/**
 * One investigation the lab offers, and what "normal" looks like for it.
 *
 * A test is both a price-list entry and a clinical definition. The price side is
 * why it carries a product; the clinical side — sample type, method, unit and
 * reference range — is what makes a printed result mean anything to the doctor
 * reading it.
 *
 * A **panel** (Lipid Profile, Liver Function Test) is a test whose `is_panel` is
 * set and whose components are listed in lab_test_components. It is billed once
 * and reported as many rows, which is exactly how a patient expects to see it.
 */
class LabTest extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    protected $fillable = [
        'code', 'name', 'category', 'sample_type', 'container', 'method', 'unit',
        'reference_range', 'ref_low', 'ref_high', 'price', 'tax_percent',
        'tat_hours', 'is_panel', 'is_active', 'product_id', 'notes',
    ];

    protected $casts = [
        'ref_low' => 'decimal:4',
        'ref_high' => 'decimal:4',
        'price' => 'decimal:2',
        'tax_percent' => 'decimal:2',
        'tat_hours' => 'integer',
        'is_panel' => 'boolean',
        'is_active' => 'boolean',
    ];

    /** The is_service product this test bills as. @see LabTestService::syncProduct() */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** The tests this panel is made of, in report order. */
    public function components(): BelongsToMany
    {
        return $this->tenantScoped($this->belongsToMany(
            self::class,
            'lab_test_components',
            'panel_test_id',
            'component_test_id',
        ))
            ->withPivot('sort_order')
            ->withTimestamps()
            ->orderBy('lab_test_components.sort_order');
    }

    /** The panels this test appears in. */
    public function panels(): BelongsToMany
    {
        return $this->tenantScoped($this->belongsToMany(
            self::class,
            'lab_test_components',
            'component_test_id',
            'panel_test_id',
        ));
    }

    /**
     * Carry the tenant onto the join table.
     *
     * A pivot gets none of BelongsToTenant's help: the trait stamps *models*,
     * and sync() writes join rows directly, so without this the insert fails on
     * a NOT NULL tenant_id. `withPivotValue` both fills it on write and filters
     * on read.
     *
     * Read from the request's tenant rather than `$this->tenant_id`, because
     * eager loading resolves the relation off a fresh instance whose attributes
     * are not populated yet — the instance value is the fallback, not the
     * source. If neither is known the constraint is simply omitted: the
     * surrounding TenantScope already fails closed, so this cannot widen a read,
     * and a write with no tenant context has bigger problems than this pivot.
     */
    private function tenantScoped(BelongsToMany $relation): BelongsToMany
    {
        $tenantId = app(TenantContext::class)->id() ?? $this->tenant_id;

        return $tenantId !== null
            ? $relation->withPivotValue('tenant_id', $tenantId)
            : $relation;
    }

    /**
     * The parameters a result sheet for this test has to carry: the components
     * of a panel, or the test itself.
     *
     * A panel with no components declared falls back to reporting itself rather
     * than producing an order with nothing to fill in — a half-configured master
     * should degrade, not block the counter.
     *
     * @return Collection<int, self>
     */
    public function reportableParameters()
    {
        if (! $this->is_panel) {
            return collect([$this]);
        }

        $components = $this->relationLoaded('components') ? $this->components : $this->components()->get();

        return $components->isNotEmpty() ? $components : collect([$this]);
    }

    /*
     * Deliberately no flagFor() here. A value is judged against the range the
     * result row was created with, not against this master's current one — see
     * LabOrderService::flagFor(). Putting the same logic on both would give two
     * answers the first time a lab revised a reference range.
     */
}
