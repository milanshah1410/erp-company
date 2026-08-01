<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\PlatformPack;
use App\Models\TenantPack;
use App\Support\IndustryTemplates;
use App\Support\Modules;
use App\Support\TenantLimits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The module marketplace catalogue for the platform panel (Volume 11 §Module
 * Marketplace → Industry / Feature / AI Packs). Guarded by `packs.*`.
 *
 * A pack is packaging over entitlement the product already has, so this screen
 * only ever composes existing module keys and metered limits — there is no way to
 * invent a capability here, which is exactly why granting a pack needs no new
 * enforcement.
 *
 * Editing a pack changes what its holders get, immediately and retroactively: the
 * entitlement is resolved from the pack row on every read. That is the right
 * behaviour for adding a module to a pack, and the reason removing one warns.
 * Deleting is refused once anyone holds it, since the grant rows and their invoices
 * reference it.
 */
class MarketplacePackController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = PlatformPack::query();

        if ($type = $request->query('type')) {
            $query->where('type', $type);
        }
        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(fn ($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('slug', 'like', "%{$search}%"));
        }
        if ($request->boolean('active_only')) {
            $query->where('is_active', true);
        }

        $packs = $query->orderBy('type')->orderBy('sort_order')->orderBy('name')->get();

        // One grouped count rather than a query per pack.
        $liveCounts = TenantPack::query()
            ->live()
            ->selectRaw('platform_pack_id, count(*) as aggregate')
            ->groupBy('platform_pack_id')
            ->pluck('aggregate', 'platform_pack_id');

        return response()->json([
            'data' => $packs->map(fn (PlatformPack $p) => $this->payload($p, (int) ($liveCounts[$p->id] ?? 0)))->values(),
            'meta' => $this->meta(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $data['slug'] = $this->slug($data['slug'] ?? null, $data['name']);

        $pack = PlatformPack::create($data);

        activity('central-billing')->causedBy($request->user())
            ->withProperties(['ip' => $request->ip(), 'pack_id' => $pack->id, 'slug' => $pack->slug])
            ->event('pack-created')
            ->log("Operator created the {$pack->name} pack");

        return response()->json(['data' => $this->payload($pack, 0)], 201);
    }

    public function update(Request $request, PlatformPack $pack): JsonResponse
    {
        $data = $this->validated($request, $pack);

        if (array_key_exists('slug', $data)) {
            $data['slug'] = $this->slug($data['slug'], $data['name'] ?? $pack->name);
        }

        $removed = array_key_exists('modules', $data)
            ? array_values(array_diff($pack->moduleKeys(), Modules::sanitize($data['modules'] ?? [])))
            : [];

        $pack->update($data);

        activity('central-billing')->causedBy($request->user())
            ->withProperties([
                'ip' => $request->ip(),
                'pack_id' => $pack->id,
                'changed' => array_keys($data),
                'modules_removed' => $removed,
            ])
            ->event('pack-updated')
            ->log("Operator updated the {$pack->name} pack");

        $live = TenantPack::query()->live()->where('platform_pack_id', $pack->id)->count();

        return response()->json([
            // Removing a module from a pack takes it away from everyone holding
            // the pack. Said out loud rather than discovered by a customer.
            'warning' => $removed !== [] && $live > 0
                ? 'Removed '.implode(', ', $removed).' — '.$live.' workspace(s) holding this pack lose those modules on their next menu load.'
                : null,
            'data' => $this->payload($pack->fresh(), $live),
        ]);
    }

    public function toggle(Request $request, PlatformPack $pack): JsonResponse
    {
        $pack->update(['is_active' => ! $pack->is_active]);

        activity('central-billing')->causedBy($request->user())
            ->withProperties(['ip' => $request->ip(), 'pack_id' => $pack->id, 'is_active' => $pack->is_active])
            ->event('pack-toggled')
            ->log('Operator '.($pack->is_active ? 'listed' : 'delisted')." the {$pack->name} pack");

        $live = TenantPack::query()->live()->where('platform_pack_id', $pack->id)->count();

        return response()->json([
            'warning' => ! $pack->is_active && $live > 0
                ? 'Delisted — the '.$live.' workspace(s) already holding it keep their entitlement until the grant is revoked.'
                : null,
            'data' => $this->payload($pack, $live),
        ]);
    }

    public function destroy(Request $request, PlatformPack $pack): JsonResponse
    {
        if (TenantPack::query()->where('platform_pack_id', $pack->id)->exists()) {
            return response()->json([
                'message' => 'This pack has been granted to at least one workspace and is referenced by billing records. Delist it instead.',
            ], 422);
        }

        $name = $pack->name;
        $pack->delete();

        activity('central-billing')->causedBy($request->user())
            ->withProperties(['ip' => $request->ip(), 'pack' => $name])
            ->event('pack-deleted')
            ->log("Operator deleted the {$name} pack");

        return response()->json(['message' => "{$name} deleted."]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?PlatformPack $existing = null): array
    {
        $sometimes = $existing ? 'sometimes' : 'required';

        $data = $request->validate([
            'slug' => ['sometimes', 'nullable', 'string', 'max:60', 'regex:/^[a-z0-9-]+$/',
                Rule::unique('platform_packs', 'slug')->ignore($existing?->id)],
            'name' => [$sometimes, 'string', 'max:255'],
            'type' => [$sometimes, Rule::in(PlatformPack::TYPES)],
            'tagline' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'icon' => ['sometimes', 'nullable', 'string', 'max:60'],

            'price' => ['sometimes', 'numeric', 'min:0'],
            'billing_cycle' => ['sometimes', Rule::in(PlatformPack::BILLING_CYCLES)],
            'currency' => ['sometimes', 'string', 'size:3'],

            'modules' => ['sometimes', 'nullable', 'array'],
            'modules.*' => ['string', Rule::in(Modules::keys())],
            'limit_bumps' => ['sometimes', 'nullable', 'array'],
            'limit_bumps.*' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'industry_key' => ['sometimes', 'nullable', 'string', Rule::in(IndustryTemplates::keys())],
            'included_in_plans' => ['sometimes', 'nullable', 'array'],
            'included_in_plans.*' => ['string', Rule::exists('plans', 'slug')],

            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
        ]);

        // A bump keyed to something nothing meters is a promise the product cannot
        // keep, so it is rejected rather than silently dropped at read time.
        foreach (array_keys($data['limit_bumps'] ?? []) as $key) {
            if (! in_array($key, TenantLimits::overridableKeys(), true)) {
                abort(response()->json([
                    'message' => "'{$key}' is not a metered limit.",
                    'errors' => ['limit_bumps' => ['Allowed keys: '.implode(', ', TenantLimits::overridableKeys()).'.']],
                ], 422));
            }
        }

        // A pack that grants nothing at all is a billing line with no product
        // behind it — the one configuration this screen refuses.
        $modules = $data['modules'] ?? $existing?->modules ?? [];
        $bumps = $data['limit_bumps'] ?? $existing?->limit_bumps ?? [];
        if ($modules === [] && $bumps === []) {
            abort(response()->json([
                'message' => 'A pack must unlock at least one module or raise at least one limit.',
                'errors' => ['modules' => ['Pick at least one module, or add a limit bump.']],
            ], 422));
        }

        return $data;
    }

    private function slug(?string $given, string $name): string
    {
        $slug = Str::slug(trim((string) ($given ?: $name)));

        return $slug !== '' ? Str::limit($slug, 60, '') : 'pack-'.Str::random(6);
    }

    /**
     * Everything the pack editor needs to build its pickers.
     *
     * @return array<string, mixed>
     */
    private function meta(): array
    {
        return [
            'types' => PlatformPack::TYPES,
            'billing_cycles' => PlatformPack::BILLING_CYCLES,
            'modules' => Modules::options(),
            'limit_keys' => TenantLimits::overridableKeys(),
            'industries' => collect(IndustryTemplates::all())
                ->map(fn (array $meta, string $key) => ['key' => $key, 'label' => $meta['label'] ?? $key])
                ->values(),
            'plans' => Plan::orderBy('sort_order')->get(['slug', 'name'])->map(fn (Plan $p) => [
                'slug' => $p->slug,
                'name' => $p->name,
            ]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(PlatformPack $p, int $liveGrants): array
    {
        return [
            'id' => $p->id,
            'slug' => $p->slug,
            'name' => $p->name,
            'type' => $p->type,
            'tagline' => $p->tagline,
            'description' => $p->description,
            'icon' => $p->icon,
            'price' => $p->price,
            'billing_cycle' => $p->billing_cycle,
            'currency' => $p->currency,
            'modules' => $p->moduleKeys(),
            'module_labels' => array_map(
                fn (string $key) => Modules::CATALOGUE[$key]['label'] ?? $key,
                $p->moduleKeys(),
            ),
            'limit_bumps' => $p->limitBumps(),
            'industry_key' => $p->industry_key,
            'included_in_plans' => $p->included_in_plans ?? [],
            'is_active' => $p->is_active,
            'sort_order' => $p->sort_order,
            'live_grants' => $liveGrants,
            'created_at' => $p->created_at?->toDateTimeString(),
        ];
    }
}
