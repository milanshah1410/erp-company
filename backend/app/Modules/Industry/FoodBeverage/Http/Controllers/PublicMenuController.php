<?php

declare(strict_types=1);

namespace App\Modules\Industry\FoodBeverage\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\RestaurantTable;
use App\Models\Setting;
use App\Models\Tenant;
use App\Support\TenantContext;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The QR menu a guest scans at the table. Unauthenticated and strictly read-only.
 *
 * Runs on the central stack with no tenant resolved from a user: the token in the
 * URL is the whole lookup, exactly as the pay page works. Tokens are unique across
 * tenants for that reason, and the tenant is activated explicitly for the duration
 * of the call.
 *
 * Read-only is a security property, not a phase. Nothing here writes, so a
 * scraped or guessed token exposes a price list — which is printed on a card and
 * handed to strangers anyway — and cannot place an order, occupy a table, or
 * reach a kitchen. There is deliberately no endpoint that would let it.
 *
 * What is *not* exposed matters as much as what is: no cost price, no margin, no
 * stock figure, no customer, no internal id beyond what a menu needs.
 */
class PublicMenuController extends Controller
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function show(string $token)
    {
        [$tenantId, $table] = $this->locate($token);

        return $this->tenantContext->runWith($tenantId, function () use ($table, $tenantId) {
            $tenant = Tenant::find($tenantId);

            $products = Product::query()
                ->with('category:id,name')
                ->where('is_active', true)
                ->where(fn ($q) => $q->where('is_service', false)->orWhereNull('is_service'))
                // A sold-out dish stays on the card, greyed: a guest who cannot
                // see it will ask for it anyway, and the waiter says no twice.
                ->orderBy('menu_sort_order')->orderBy('name')
                ->get([
                    'id', 'name', 'description', 'category_id', 'selling_price',
                    'food_type', 'prep_minutes', 'is_available',
                ]);

            $groups = $products
                ->groupBy(fn (Product $p) => $p->category?->name ?: 'Other')
                ->map(fn ($items, $category) => [
                    'category' => $category,
                    'items' => $items->map(fn (Product $p) => [
                        'name' => $p->name,
                        'description' => $p->description,
                        'price' => (float) $p->selling_price,
                        'food_type' => $p->food_type,
                        'prep_minutes' => $p->prep_minutes,
                        'is_available' => (bool) $p->is_available,
                    ])->values(),
                ])
                ->values();

            return response()->json(['data' => [
                'company_name' => $tenant?->company_name,
                'currency_symbol' => Setting::currencySymbol(),
                'table' => $table?->name,
                'groups' => $groups,
                // Stated so the page can say so plainly, rather than leaving a
                // guest hunting for an "order" button that was never there.
                'ordering_enabled' => false,
            ]]);
        });
    }

    /**
     * Resolve a token to its tenant, and to a table when the sticker was a table's.
     *
     * Both lookups bypass tenant scope — there is no tenant yet, that is what is
     * being determined. A miss is a plain 404: distinguishing "no such token" from
     * "token belongs to a suspended shop" would leak which tokens are real.
     *
     * @return array{0: int, 1: RestaurantTable|null}
     */
    private function locate(string $token): array
    {
        $table = RestaurantTable::withoutGlobalScopes()
            ->where('qr_token', $token)
            ->first(['id', 'tenant_id', 'name', 'is_active']);

        if ($table) {
            if (! $table->is_active) {
                throw new NotFoundHttpException('Menu not available.');
            }

            return [(int) $table->tenant_id, $table];
        }

        $setting = Setting::withoutGlobalScopes()
            ->where('key', 'restaurant_menu_token')
            ->where('value', $token)
            ->first(['tenant_id']);

        if (! $setting) {
            throw new NotFoundHttpException('Menu not available.');
        }

        return [(int) $setting->tenant_id, null];
    }
}
