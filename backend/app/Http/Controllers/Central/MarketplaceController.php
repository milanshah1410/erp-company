<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\ChannelIntegration;
use App\Models\Device;
use App\Models\MarketplaceItem;
use App\Models\PaymentGatewayAccount;
use App\Models\Plan;
use App\Models\Tenant;
use App\Modules\DeviceManager\Support\DeviceRegistry;
use App\Support\IntegrationCatalogue;
use App\Support\MarketplaceGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The SaaS owner's marketplace (Volume 11 §"Integration Marketplace" and
 * §"Device Marketplace").
 *
 * Two catalogues, one control surface, because the operator question is
 * identical for both: what do we offer, who gets it, and who took it up. The
 * catalogue entries themselves are code — a connector or a device adapter ships
 * in a release — so this screen never creates or deletes anything. It sets the
 * three levers that belong to commerce rather than engineering: on sale, which
 * plans, and whether to flag it as beta.
 *
 * Rows are addressed by catalogue key, not id. The key is what an operator and
 * the code both mean by "Razorpay", it survives a re-seed, and it lets a lever
 * be pulled on an entry whose row has not been materialised yet — which happens
 * the first time the panel is opened after a deploy that shipped a connector.
 *
 * Adoption counts are measured, not configured: they come from the tenant tables
 * the integration actually writes to. An entry showing zero after a year is the
 * most useful thing this screen can tell an owner.
 */
class MarketplaceController extends Controller
{
    // -----------------------------------------------------------------------
    // Integrations
    // -----------------------------------------------------------------------

    public function integrations(): JsonResponse
    {
        MarketplaceItem::syncCatalogue();

        $rows = $this->rowsFor(MarketplaceItem::KIND_INTEGRATION);
        $adoption = IntegrationCatalogue::adoption();

        $items = [];
        foreach (IntegrationCatalogue::all() as $key => $meta) {
            $row = $rows[$key] ?? null;

            $items[] = [
                'key' => $key,
                'label' => $meta['label'],
                'category' => $meta['category'],
                'category_label' => IntegrationCatalogue::CATEGORIES[$meta['category']] ?? $meta['category'],
                'icon' => $meta['icon'],
                'vendor' => $meta['vendor'],
                'description' => $meta['description'],
                // Roadmap entries are listed but can never be switched on: there
                // is no connector behind them, and a toggle that promises one
                // would be a lie told to the operator, then to their customer.
                'status' => $meta['status'],
                'is_available' => $meta['status'] === 'live',
                'module' => $meta['module'],
                'configured_at' => $meta['configured_at'],
                'is_enabled' => $row?->is_enabled ?? ($meta['status'] === 'live'),
                'is_beta' => $row?->is_beta ?? false,
                'plan_slugs' => $row?->plan_slugs,
                'note' => $row?->note,
                'tenants_count' => $adoption[$key] ?? 0,
            ];
        }

        return response()->json([
            'data' => $items,
            'categories' => IntegrationCatalogue::CATEGORIES,
            'plans' => $this->planOptions(),
        ]);
    }

    public function updateIntegration(Request $request, string $key): JsonResponse
    {
        if (! IntegrationCatalogue::has($key)) {
            return response()->json(['message' => "Unknown integration [{$key}]."], 404);
        }

        $data = $this->validateLevers($request);

        if (($data['is_enabled'] ?? false) && ! IntegrationCatalogue::isLive($key)) {
            $label = IntegrationCatalogue::get($key)['label'];

            return response()->json([
                'message' => "{$label} is on the roadmap — the connector has not shipped yet, so it cannot be put on sale.",
            ], 422);
        }

        $item = $this->applyLevers(MarketplaceItem::KIND_INTEGRATION, $key, $data);

        activity('central-marketplace')->causedBy($request->user())
            ->withProperties(['ip' => $request->ip(), 'key' => $key, 'changes' => $data])
            ->event('updated')
            ->log('Operator updated integration "'.IntegrationCatalogue::get($key)['label'].'" in the marketplace');

        return response()->json(['data' => $item]);
    }

    /**
     * Who actually connected this integration — the answer to "can we retire
     * it?", which no aggregate count can give you.
     */
    public function integrationTenants(string $key): JsonResponse
    {
        $meta = IntegrationCatalogue::get($key);

        if ($meta === null) {
            return response()->json(['message' => "Unknown integration [{$key}]."], 404);
        }

        $adoption = $meta['adoption'];

        $tenantIds = match ($adoption[0]) {
            'channel' => ChannelIntegration::allTenants()->where('platform', $adoption[1])->distinct()->pluck('tenant_id'),
            'gateway' => PaymentGatewayAccount::allTenants()->where('gateway', $adoption[1])->distinct()->pluck('tenant_id'),
            'setting' => DB::table('settings')->where('key', $adoption[1])->where('value', $adoption[2])->distinct()->pluck('tenant_id'),
            'table' => $adoption[1]::allTenants()->distinct()->pluck('tenant_id'),
            default => collect(),
        };

        $tenants = Tenant::query()
            ->with('subscription.plan')
            ->whereIn('id', $tenantIds)
            ->orderBy('company_name')
            ->get();

        return response()->json([
            'data' => $tenants->map(fn (Tenant $t) => [
                'id' => $t->id,
                'company_name' => $t->company_name,
                'subdomain' => $t->subdomain,
                'status' => $t->status,
                'plan' => $t->subscription?->plan?->name,
            ])->values(),
        ]);
    }

    // -----------------------------------------------------------------------
    // Devices
    // -----------------------------------------------------------------------

    public function devices(): JsonResponse
    {
        MarketplaceItem::syncCatalogue();

        $rows = $this->rowsFor(MarketplaceItem::KIND_DEVICE);

        // Two grouped queries cover every count on the screen: one per device
        // class, one per brand within it.
        $byType = Device::allTenants()
            ->select('type', DB::raw('count(distinct tenant_id) as tenants'), DB::raw('count(*) as devices'))
            ->groupBy('type')
            ->get()
            ->keyBy('type');

        $byDriver = Device::allTenants()
            ->select('type', 'driver', DB::raw('count(distinct tenant_id) as tenants'), DB::raw('count(*) as devices'))
            ->groupBy('type', 'driver')
            ->get()
            ->keyBy(fn ($r) => "{$r->type}:{$r->driver}");

        $drivers = DeviceRegistry::drivers();
        $types = [];

        foreach (DeviceRegistry::TYPES as $type => $meta) {
            $typeRow = $rows[$type] ?? null;

            $brands = [];
            foreach ($drivers[$type] ?? [] as $driverKey => $driver) {
                $key = MarketplaceItem::deviceKey($type, $driverKey);
                $row = $rows[$key] ?? null;
                $counts = $byDriver[$key] ?? null;

                $brands[] = [
                    'key' => $key,
                    'driver' => $driverKey,
                    'label' => $driver['label'],
                    'models' => $driver['models'],
                    'is_enabled' => $row?->is_enabled ?? true,
                    'is_beta' => $row?->is_beta ?? false,
                    'plan_slugs' => $row?->plan_slugs,
                    'note' => $row?->note,
                    'tenants_count' => (int) ($counts->tenants ?? 0),
                    'devices_count' => (int) ($counts->devices ?? 0),
                ];
            }

            $typeCounts = $byType[$type] ?? null;

            $types[] = [
                'key' => $type,
                'type' => $type,
                'label' => $meta['label'],
                'icon' => $meta['icon'],
                'description' => $meta['description'],
                'is_enabled' => $typeRow?->is_enabled ?? true,
                'is_beta' => $typeRow?->is_beta ?? false,
                'plan_slugs' => $typeRow?->plan_slugs,
                'note' => $typeRow?->note,
                'tenants_count' => (int) ($typeCounts->tenants ?? 0),
                'devices_count' => (int) ($typeCounts->devices ?? 0),
                'drivers' => $brands,
            ];
        }

        return response()->json([
            'data' => $types,
            'plans' => $this->planOptions(),
        ]);
    }

    /**
     * $key is either a device class ('barcode') or a class:brand pair
     * ('barcode:zebra'). Withdrawing the class withdraws every brand under it;
     * withdrawing a brand leaves the rest of the class on sale.
     */
    public function updateDevice(Request $request, string $key): JsonResponse
    {
        [$type, $driver] = array_pad(explode(':', $key, 2), 2, null);

        if (! DeviceRegistry::hasType($type)) {
            return response()->json(['message' => "Unknown device type [{$type}]."], 404);
        }

        if ($driver !== null && ! DeviceRegistry::has($type, $driver)) {
            return response()->json(['message' => "Unknown device brand [{$key}]."], 404);
        }

        $data = $this->validateLevers($request);
        $item = $this->applyLevers(MarketplaceItem::KIND_DEVICE, $key, $data);

        $label = $driver === null
            ? DeviceRegistry::TYPES[$type]['label']
            : DeviceRegistry::TYPES[$type]['label'].' — '.DeviceRegistry::driver($type, $driver)['label'];

        activity('central-marketplace')->causedBy($request->user())
            ->withProperties(['ip' => $request->ip(), 'key' => $key, 'changes' => $data])
            ->event('updated')
            ->log("Operator updated device offering \"{$label}\" in the marketplace");

        return response()->json(['data' => $item]);
    }

    // -----------------------------------------------------------------------
    // Shared
    // -----------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function validateLevers(Request $request): array
    {
        $planSlugs = Plan::query()->pluck('slug')->all();

        $data = $request->validate([
            'is_enabled' => ['sometimes', 'boolean'],
            'is_beta' => ['sometimes', 'boolean'],
            // An empty array and null both mean "every plan"; normalised below
            // so the gate only ever sees one representation of that.
            'plan_slugs' => ['sometimes', 'nullable', 'array'],
            'plan_slugs.*' => ['string', 'in:'.implode(',', $planSlugs)],
            'note' => ['sometimes', 'nullable', 'string', 'max:255'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);

        if (array_key_exists('plan_slugs', $data) && $data['plan_slugs'] === []) {
            $data['plan_slugs'] = null;
        }

        // Drop absent keys so a partial update never nulls what it omitted.
        return array_filter(
            $data,
            static fn ($value, $key) => $request->has($key),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    private function applyLevers(string $kind, string $key, array $data): MarketplaceItem
    {
        $item = MarketplaceItem::firstOrNew(['kind' => $kind, 'key' => $key]);
        $item->fill($data);
        $item->save();

        // The gate caches the whole row map for ten minutes; an operator toggling
        // an offering expects it to take effect now, not eventually.
        MarketplaceGate::flush();

        return $item->fresh();
    }

    /**
     * @return array<string, MarketplaceItem>  catalogue key => row
     */
    private function rowsFor(string $kind): array
    {
        return MarketplaceItem::query()
            ->kind($kind)
            ->get()
            ->keyBy('key')
            ->all();
    }

    /** Plan slugs + names, so the panel's plan picker needs no second request. */
    private function planOptions(): array
    {
        return Plan::query()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['slug', 'name'])
            ->map(fn (Plan $p) => ['slug' => $p->slug, 'name' => $p->name])
            ->values()
            ->all();
    }
}
