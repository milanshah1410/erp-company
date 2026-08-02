<?php

declare(strict_types=1);

namespace App\Modules\Industry\Toys\Services;

use App\Support\EnabledModules;
use App\Support\TenantContext;
use RuntimeException;

/**
 * The one door the counter opens onto this vertical.
 *
 * PosController is core, shared by every trade the product sells, and it already
 * carries a pharmacy gate, a jewellery pricer, a voucher tender, a combo-pack
 * expander and a credit check. Every vertical that reaches into it should reach
 * in exactly once, through a class that can be asked a question and will answer
 * "no, not your business" for a workspace that never bought the module.
 *
 * That is all this is. The reasoning about age bands, hazards and certificates
 * stays in SafetyComplianceService; this decides only whether the till should be
 * asking at all.
 */
class ToyCounterService
{
    public function __construct(private readonly SafetyComplianceService $safety) {}

    /** Is this workspace a toy shop? */
    public function enabled(): bool
    {
        $modules = EnabledModules::forTenant(app(TenantContext::class)->id());

        // Null means the workspace predates module selection — the same fail-open
        // rule the module middleware and the nav both follow. Fail-open is safe
        // here in the sense that matters: the check only ever *refuses* sales, so
        // a workspace it cannot classify keeps an ordinary till.
        return $modules !== null && in_array('toys', $modules, true);
    }

    /**
     * Refuse a bill carrying something the shop may not legally sell.
     *
     * Called before a single row is written, so a refused sale leaves no stock
     * movement or half-built order behind — the same contract the pharmacy's
     * prescription gate keeps at the same point in the checkout.
     *
     * @param  list<array<string, mixed>>  $items
     *
     * @throws RuntimeException
     */
    public function assertSellable(array $items): void
    {
        if (! $this->enabled()) {
            return;
        }

        $this->safety->assertSellable($items);
    }
}
