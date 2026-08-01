<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DeviceRequest;
use App\Http\Resources\DeviceResource;
use App\Models\Device;
use App\Modules\DeviceManager\Data\PrintJob;
use App\Modules\DeviceManager\Support\DeviceRegistry;
use App\Services\DeviceManagerService;
use App\Support\MarketplaceGate;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Device Manager: configure hardware, test it, drive it, and simulate it.
 *
 * The configuration screen is rendered from `catalogue()` — the same registry the
 * drivers are resolved from — so a newly registered brand appears in the UI with
 * its own fields without a single frontend change.
 */
class DeviceController extends Controller
{
    public function __construct(private readonly DeviceManagerService $devices) {}

    /**
     * Supported device classes, brand adapters and their configuration fields —
     * filtered to what the SaaS owner offers this workspace (Volume 11
     * §"Device Marketplace").
     *
     * A withdrawn class disappears entirely; a withdrawn brand disappears from
     * an otherwise intact class. A class whose every brand was withdrawn is
     * dropped rather than shown empty.
     */
    public function catalogue()
    {
        $tenantId = app(TenantContext::class)->id();

        $catalogue = [];
        foreach (DeviceRegistry::forFrontend() as $type) {
            if (! MarketplaceGate::allowsDevice($type['type'], null, $tenantId)) {
                continue;
            }

            $type['drivers'] = array_values(array_filter(
                $type['drivers'],
                fn (array $d) => MarketplaceGate::allowsDevice($type['type'], $d['driver'], $tenantId),
            ));

            if ($type['drivers'] !== []) {
                $catalogue[] = $type;
            }
        }

        return response()->json(['data' => $catalogue]);
    }

    public function index(Request $request)
    {
        $query = Device::query()->with(['warehouse:id,name', 'biometricDevice:id,name']);

        if ($request->filled('type')) {
            $query->where('type', $request->query('type'));
        }
        if ($request->filled('mode')) {
            $query->where('mode', $request->query('mode'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }
        if ($request->filled('q')) {
            $term = '%' . $request->query('q') . '%';
            $query->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('code', 'like', $term));
        }

        $query->orderBy('type')->orderByDesc('is_default')->orderBy('name');

        return DeviceResource::collection($query->get());
    }

    public function store(DeviceRequest $request)
    {
        $data = $request->validated();

        // The catalogue endpoint already hides withdrawn brands; this refuses
        // the guessable POST behind it. Only new devices are gated — type and
        // driver are create-only, so an already-configured terminal keeps
        // working after its brand is withdrawn from sale.
        if (! MarketplaceGate::allowsDevice($data['type'], $data['driver'], app(TenantContext::class)->id())) {
            return response()->json([
                'message' => 'That device is not available on your plan. Contact support to enable it.',
                'error' => 'device_unavailable',
            ], 403);
        }

        $device = new Device([
            'type' => $data['type'],
            'driver' => $data['driver'],
            'code' => $data['code'],
            'name' => $data['name'],
            'location' => $data['location'] ?? null,
            // New devices start simulated so a screen can be wired up and
            // demonstrated before the hardware is bought or delivered.
            'mode' => $data['mode'] ?? 'simulator',
            'is_active' => $data['is_active'] ?? true,
            'is_default' => $data['is_default'] ?? false,
            'settings' => array_merge(
                DeviceRegistry::defaultSettings($data['type'], $data['driver']),
                $data['settings'] ?? [],
            ),
            'warehouse_id' => $data['warehouse_id'] ?? null,
            'biometric_device_id' => $data['biometric_device_id'] ?? null,
        ]);
        $device->credentials = $this->mergeCredentials([], $data['credentials'] ?? []);
        $device->save();

        $this->settleDefault($device);

        return (new DeviceResource($device->load(['warehouse', 'biometricDevice'])))
            ->response()->setStatusCode(201);
    }

    public function show(Device $device)
    {
        return new DeviceResource($device->load(['warehouse', 'biometricDevice']));
    }

    public function update(DeviceRequest $request, Device $device)
    {
        $data = $request->validated();

        foreach (['code', 'name', 'location', 'mode', 'is_active', 'is_default', 'warehouse_id', 'biometric_device_id'] as $key) {
            if (array_key_exists($key, $data)) {
                $device->{$key} = $data[$key];
            }
        }

        if (array_key_exists('settings', $data)) {
            // Merge, so a dialog that only rendered one brand's fields cannot
            // wipe options saved by another version of the screen.
            $device->settings = array_merge($device->settings ?? [], $data['settings'] ?? []);
        }

        if (! empty($data['credentials'])) {
            $device->credentials = $this->mergeCredentials($device->credentials ?? [], $data['credentials']);
        }

        $device->save();
        $this->settleDefault($device);

        return new DeviceResource($device->load(['warehouse', 'biometricDevice']));
    }

    public function destroy(Device $device)
    {
        $device->delete();

        return response()->json(['message' => 'Device removed.']);
    }

    /** Test Connection. */
    public function test(Device $device)
    {
        $result = $this->devices->testConnection($device);

        return response()->json([
            'ok' => $result->ok,
            'message' => $result->message,
            'details' => $result->details,
            'latency_ms' => $result->latencyMs,
            'simulated' => $result->simulated,
            'data' => new DeviceResource($device->fresh()->load(['warehouse', 'biometricDevice'])),
        ], $result->ok ? 200 : 422);
    }

    /**
     * Fire a simulated event: mock scan, print preview, fake weight, virtual tag
     * read or virtual fingerprint punch — driven by the device's own type.
     */
    public function simulate(Request $request, Device $device)
    {
        $params = $request->validate([
            'code' => ['nullable', 'string', 'max:255'],           // barcode
            'document' => ['nullable', 'string', 'max:30'],          // printer
            'copies' => ['nullable', 'integer', 'min:1', 'max:20'],
            'job' => ['nullable', 'array'],
            'weight' => ['nullable', 'numeric'],                     // scale
            'stable' => ['nullable', 'boolean'],
            'epcs' => ['nullable'],                                  // rfid
            'device_user_ids' => ['nullable'],                       // biometric
            'count' => ['nullable', 'integer', 'min:1', 'max:50'],
            'direction' => ['nullable', 'in:in,out'],
            'ingest' => ['nullable', 'boolean'],
        ]);

        return $this->attempt(
            fn () => $this->devices->simulate($device, $params),
            'Simulated event recorded.',
            $device,
        );
    }

    /** Run a real operation against the device (or its simulator, per mode). */
    public function run(Request $request, Device $device, string $action)
    {
        $payload = $request->all();

        return $this->attempt(function () use ($device, $action, $request, $payload) {
            return match ([$device->type, $action]) {
                ['barcode', 'decode'] => ['scan' => $this->devices->decodeScan(
                    $device,
                    (string) $request->validate(['raw' => ['required', 'string', 'max:512']])['raw'],
                )->toArray()],

                ['barcode', 'poll'] => ['scan' => $this->devices->pollScan($device)?->toArray()],

                ['printer', 'print'] => ['print' => $this->devices->print(
                    $device,
                    PrintJob::fromArray($payload['job'] ?? $payload),
                )->toArray()],

                ['printer', 'open_drawer'] => ['print' => $this->devices->openDrawer($device)->toArray()],

                ['weight_scale', 'read'] => ['weight' => $this->devices->weigh($device)->toArray()],
                ['weight_scale', 'tare'] => ['weight' => $this->devices->tare($device)->toArray()],

                ['rfid', 'inventory'] => ['tags' => array_map(
                    fn ($t) => $t->toArray(),
                    $this->devices->readTags($device, (int) ($payload['duration_ms'] ?? 1000)),
                )],

                ['biometric', 'pull_punches'] => ['stats' => $this->devices->pullPunches(
                    $device,
                    isset($payload['since']) && $payload['since'] !== '' ? new \DateTimeImmutable((string) $payload['since']) : null,
                )],

                default => abort(422, sprintf(
                    '"%s" is not supported by a %s device.',
                    $action,
                    DeviceRegistry::TYPES[$device->type]['label'] ?? $device->type,
                )),
            };
        }, 'Done.', $device);
    }

    /**
     * Run an operation, translating hardware/driver failures into a 422 with the
     * driver's own message — which is what the operator needs to see, not a 500.
     */
    private function attempt(callable $operation, string $message, Device $device)
    {
        try {
            $result = $operation();
        } catch (Throwable $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'data' => new DeviceResource($device->fresh()->load(['warehouse', 'biometricDevice'])),
            ], 422);
        }

        return response()->json([
            'message' => $message,
            'result' => $result,
            'data' => new DeviceResource($device->fresh()->load(['warehouse', 'biometricDevice'])),
        ]);
    }

    /** Only one default device per type — the POS/label screens rely on it. */
    private function settleDefault(Device $device): void
    {
        if (! $device->is_default) {
            return;
        }

        DB::table('devices')
            ->where('tenant_id', $device->tenant_id)
            ->where('type', $device->type)
            ->where('id', '!=', $device->id)
            ->update(['is_default' => false]);
    }

    /** Overlay only the non-blank incoming secrets, so "leave blank to keep" works. */
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
