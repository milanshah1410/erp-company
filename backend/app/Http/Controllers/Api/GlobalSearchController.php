<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Global search (SRS §22 "Chat-based ERP Search" — omni search bar).
 *
 * Fans a single query out across the shared Meilisearch indexes for products,
 * customers and invoices. Every index is multi-tenant, so each search is
 * constrained with ->where('tenant_id', <current tenant>) against the
 * filterable `tenant_id` attribute (see config/scout.php). A group is only
 * queried when the caller holds the matching view permission, so results never
 * leak a module the user can't otherwise see.
 */
class GlobalSearchController extends Controller
{
    /** Minimum query length before we hit the engine. */
    private const MIN_CHARS = 2;

    public function index(Request $request, TenantContext $tenant): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        $perGroup = max(1, min(20, (int) $request->integer('limit', 6)));
        $user = $request->user();

        if (mb_strlen($q) < self::MIN_CHARS) {
            return response()->json(['query' => $q, 'groups' => []]);
        }

        $tenantId = $tenant->id();
        $groups = [];

        if ($user?->can('products.view')) {
            $groups[] = $this->group('products', 'Products', $this->products($q, $tenantId, $perGroup));
        }

        if ($user?->can('customers.view')) {
            $groups[] = $this->group('customers', 'Customers', $this->customers($q, $tenantId, $perGroup));
        }

        if ($user?->can('sales.view')) {
            $groups[] = $this->group('invoices', 'Invoices', $this->invoices($q, $tenantId, $perGroup));
        }

        return response()->json([
            'query' => $q,
            'groups' => array_values(array_filter($groups, fn (array $g) => $g['items']->isNotEmpty())),
        ]);
    }

    /** @return Collection<int, array<string, mixed>> */
    private function products(string $q, ?int $tenantId, int $limit): Collection
    {
        return Product::search($q)
            ->where('tenant_id', $tenantId)
            ->take($limit)
            ->get()
            ->map(fn (Product $p) => [
                'id' => $p->id,
                'title' => $p->name,
                'subtitle' => trim(implode(' · ', array_filter([$p->sku, $p->barcode]))),
                'badge' => $p->is_active ? null : 'Inactive',
            ]);
    }

    /** @return Collection<int, array<string, mixed>> */
    private function customers(string $q, ?int $tenantId, int $limit): Collection
    {
        return Customer::search($q)
            ->where('tenant_id', $tenantId)
            ->take($limit)
            ->get()
            ->map(fn (Customer $c) => [
                'id' => $c->id,
                'title' => $c->name,
                'subtitle' => trim(implode(' · ', array_filter([$c->company_name, $c->phone, $c->city]))),
                'badge' => $c->is_active ? null : 'Inactive',
            ]);
    }

    /** @return Collection<int, array<string, mixed>> */
    private function invoices(string $q, ?int $tenantId, int $limit): Collection
    {
        // Meilisearch sorts on the indexed integer `invoice_date_ts` (a computed
        // field, not a column). The collection/database drivers apply orderBy as
        // real SQL, so they must sort on the actual `invoice_date` column — ISO
        // date strings sort chronologically either way.
        $dateSort = config('scout.driver') === 'meilisearch' ? 'invoice_date_ts' : 'invoice_date';

        return Invoice::search($q)
            ->where('tenant_id', $tenantId)
            ->orderBy($dateSort, 'desc')
            ->take($limit)
            ->get()
            ->map(fn (Invoice $i) => [
                'id' => $i->id,
                'title' => $i->invoice_number,
                'subtitle' => trim(implode(' · ', array_filter([
                    $i->customer?->name,
                    $i->invoice_date?->format('d M Y'),
                ]))),
                'badge' => $i->status,
            ]);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $items
     * @return array{type: string, label: string, total: int, items: Collection<int, array<string, mixed>>}
     */
    private function group(string $type, string $label, Collection $items): array
    {
        return [
            'type' => $type,
            'label' => $label,
            'total' => $items->count(),
            'items' => $items,
        ];
    }
}
