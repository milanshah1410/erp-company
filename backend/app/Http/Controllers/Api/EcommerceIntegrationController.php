<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ChannelIntegrationResource;
use App\Models\ChannelIntegration;
use App\Services\Ecommerce\ChannelSyncService;
use App\Services\Ecommerce\PlatformRegistry;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Manage e-commerce channel connections (SRS §15) and drive their syncs:
 * connection test, order pull, inventory push, and listing pull. Credentials
 * are stored encrypted and never returned.
 */
class EcommerceIntegrationController extends Controller
{
    public function __construct(private readonly ChannelSyncService $sync) {}

    /** Supported platforms + the fields the connect dialog must collect. */
    public function platforms()
    {
        return response()->json(['data' => PlatformRegistry::forFrontend()]);
    }

    public function index(Request $request)
    {
        $query = ChannelIntegration::query()
            ->with('warehouse:id,name')
            ->withCount(['listings', 'orders'])
            ->orderBy('platform');

        if ($request->filled('platform')) {
            $query->where('platform', $request->query('platform'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        // Pending inbox count per integration, for the dashboard badges.
        $integrations = $query->get()->each(function (ChannelIntegration $i) {
            $i->pending_orders_count = $i->orders()->where('import_status', 'pending')->count();
        });

        return ChannelIntegrationResource::collection($integrations);
    }

    public function store(Request $request)
    {
        $data = $this->validatePayload($request, creating: true);

        $integration = new ChannelIntegration([
            'platform' => $data['platform'],
            'name' => $data['name'],
            'warehouse_id' => $data['warehouse_id'] ?? null,
            'settings' => $data['settings'] ?? [],
            'auto_sync_orders' => $data['auto_sync_orders'] ?? false,
            'auto_push_inventory' => $data['auto_push_inventory'] ?? false,
            'status' => 'disconnected',
        ]);
        $integration->credentials = $this->mergeCredentials([], $data['credentials'] ?? []);
        $integration->save();

        return (new ChannelIntegrationResource($integration->load('warehouse')))
            ->response()->setStatusCode(201);
    }

    public function show(ChannelIntegration $ecommerceIntegration)
    {
        return new ChannelIntegrationResource($ecommerceIntegration->load('warehouse')->loadCount(['listings', 'orders']));
    }

    public function update(Request $request, ChannelIntegration $ecommerceIntegration)
    {
        $data = $this->validatePayload($request, creating: false);

        $ecommerceIntegration->fill(array_filter([
            'name' => $data['name'] ?? null,
            'warehouse_id' => array_key_exists('warehouse_id', $data) ? $data['warehouse_id'] : $ecommerceIntegration->warehouse_id,
        ], fn ($v) => $v !== null));

        if (array_key_exists('settings', $data)) {
            $ecommerceIntegration->settings = $data['settings'];
        }
        if (array_key_exists('auto_sync_orders', $data)) {
            $ecommerceIntegration->auto_sync_orders = $data['auto_sync_orders'];
        }
        if (array_key_exists('auto_push_inventory', $data)) {
            $ecommerceIntegration->auto_push_inventory = $data['auto_push_inventory'];
        }
        if (! empty($data['credentials'])) {
            $ecommerceIntegration->credentials = $this->mergeCredentials(
                $ecommerceIntegration->credentials ?? [],
                $data['credentials'],
            );
        }

        $ecommerceIntegration->save();

        return new ChannelIntegrationResource($ecommerceIntegration->load('warehouse'));
    }

    public function destroy(ChannelIntegration $ecommerceIntegration)
    {
        $ecommerceIntegration->delete();

        return response()->json(['message' => 'Integration removed.']);
    }

    /** Verify stored credentials against the platform. */
    public function test(ChannelIntegration $ecommerceIntegration)
    {
        $result = $this->sync->testConnection($ecommerceIntegration);

        return response()->json([
            'ok' => $result->ok,
            'message' => $result->message,
            'data' => new ChannelIntegrationResource($ecommerceIntegration->fresh()->load('warehouse')),
        ], $result->ok ? 200 : 422);
    }

    /** Pull marketplace orders into the inbox. */
    public function syncOrders(ChannelIntegration $ecommerceIntegration)
    {
        return $this->run(fn () => $this->sync->syncOrders($ecommerceIntegration), 'Orders synced.');
    }

    /** Push current stock levels out to the channel. */
    public function pushInventory(ChannelIntegration $ecommerceIntegration)
    {
        return $this->run(fn () => $this->sync->pushInventory($ecommerceIntegration), 'Inventory pushed.');
    }

    /** Pull listings and auto-map any matching local SKUs. */
    public function syncListings(ChannelIntegration $ecommerceIntegration)
    {
        return $this->run(fn () => $this->sync->syncListings($ecommerceIntegration), 'Listings synced.');
    }

    /** Run a sync closure, translating connector failures into a 422. */
    private function run(callable $fn, string $message)
    {
        try {
            $stats = $fn();
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => $message, 'stats' => $stats]);
    }

    private function validatePayload(Request $request, bool $creating): array
    {
        $rules = [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:120'],
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'settings' => ['nullable', 'array'],
            'credentials' => ['nullable', 'array'],
            'auto_sync_orders' => ['boolean'],
            'auto_push_inventory' => ['boolean'],
        ];
        if ($creating) {
            $rules['platform'] = ['required', 'string', Rule::in(PlatformRegistry::keys())];
        }

        return $request->validate($rules);
    }

    /** Overlay only the non-empty incoming credential values onto the existing set. */
    private function mergeCredentials(array $existing, array $incoming): array
    {
        foreach ($incoming as $key => $value) {
            if ($value !== null && $value !== '') {
                $existing[$key] = (string) $value;
            }
        }

        return $existing;
    }
}
