<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\PlatformPack;
use App\Models\Tenant;
use App\Models\TenantPack;
use App\Services\Billing\PackEntitlementService;
use App\Support\PackEntitlement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Who holds which marketplace pack (Volume 11 §Module Marketplace). Guarded by
 * `packs.view` / `packs.assign`.
 *
 * Granting is the operator-side sale: pick a workspace, pick a pack, and the money
 * and the entitlement are recorded together — a `purchased` grant raises a GST
 * invoice against the tenant's subscription, a `comped` one is free by definition.
 * Both flow through {@see PackEntitlementService}, which is what makes the modules
 * appear in the workspace's menu.
 *
 * Plan-bundled packs never appear here. They entitle without a grant row, resolved
 * from the tenant's plan on every read, so a downgrade removes them with no
 * bookkeeping to chase — see {@see PackEntitlement}.
 */
class MarketplaceAssignmentController extends Controller
{
    public function __construct(private PackEntitlementService $packs) {}

    public function index(Request $request): JsonResponse
    {
        $query = TenantPack::query()->with('pack');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($packId = $request->query('pack_id')) {
            $query->where('platform_pack_id', $packId);
        }
        if ($tenantId = $request->query('tenant_id')) {
            $query->where('tenant_id', $tenantId);
        }
        if ($search = trim((string) $request->query('search', ''))) {
            // Tenants are on the landlord connection — resolve ids first rather
            // than relying on a cross-connection whereHas.
            $query->whereIn('tenant_id', Tenant::query()
                ->where(fn ($q) => $q
                    ->where('company_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%"))
                ->pluck('id'));
        }

        $perPage = min((int) $request->query('per_page', 20), 100);
        $page = $query->orderByDesc('id')->paginate($perPage);

        $tenants = Tenant::whereIn('id', collect($page->items())->pluck('tenant_id')->unique())
            ->get(['id', 'company_name', 'status'])
            ->keyBy('id');

        return response()->json([
            'data' => collect($page->items())->map(fn (TenantPack $g) => $this->payload($g, $tenants->get($g->tenant_id)))->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
            'packs' => PlatformPack::orderBy('name')->get(['id', 'name', 'type'])->map(fn (PlatformPack $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'type' => $p->type,
            ]),
        ]);
    }

    /**
     * The marketplace as one workspace sees it: held, bundled with its plan, or
     * available to sell. Backs the grant dialog's pack picker.
     */
    public function forTenant(Tenant $tenant): JsonResponse
    {
        return response()->json([
            'tenant' => [
                'id' => $tenant->id,
                'company_name' => $tenant->company_name,
                'status' => $tenant->status,
            ],
            'data' => $this->packs->catalogueFor($tenant),
            // What the packs currently entitle, resolved the same way the
            // workspace's menu resolves it.
            'entitlement' => [
                'modules' => PackEntitlement::modulesFor($tenant->id),
                'limit_bumps' => PackEntitlement::limitBumpsFor($tenant->id),
                'packs' => PackEntitlement::packsFor($tenant->id),
            ],
        ]);
    }

    /** Grant a pack to a workspace. */
    public function store(Request $request, Tenant $tenant): JsonResponse
    {
        $data = $request->validate([
            'pack_id' => ['required', 'integer', Rule::exists('platform_packs', 'id')],
            'source' => ['sometimes', Rule::in(TenantPack::SOURCES)],
            'price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date', 'after:starts_at'],
            'coupon_code' => ['sometimes', 'nullable', 'string', 'max:40'],
            'bill' => ['sometimes', 'boolean'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        // `bundled` is not a grant an operator makes — it is what a plan implies.
        // Listing the pack against the plan is how that is expressed.
        if (($data['source'] ?? 'purchased') === 'bundled') {
            return response()->json([
                'message' => 'A bundled pack comes from the plan. Add this plan to the pack\'s "included in plans" list instead.',
            ], 422);
        }

        $pack = PlatformPack::findOrFail($data['pack_id']);

        $grant = $this->packs->grant($tenant, $pack, [
            'source' => $data['source'] ?? 'purchased',
            'price' => $data['price'] ?? null,
            'starts_at' => $data['starts_at'] ?? null,
            'ends_at' => $data['ends_at'] ?? null,
            'coupon_code' => $data['coupon_code'] ?? null,
            'bill' => $data['bill'] ?? true,
            'notes' => $data['notes'] ?? null,
            'granted_by' => $request->user()?->id,
        ]);

        activity('central-billing')->causedBy($request->user())
            ->withProperties([
                'ip' => $request->ip(),
                'tenant_id' => $tenant->id,
                'pack' => $pack->slug,
                'source' => $grant->source,
                'price' => $grant->price,
                'invoice_id' => $grant->subscription_invoice_id,
            ])
            ->event('pack-granted')
            ->log("Operator granted the {$pack->name} pack to \"{$tenant->company_name}\"");

        return response()->json([
            'message' => $grant->subscription_invoice_id
                ? $pack->name.' granted — invoice '.($grant->invoice?->displayNumber() ?? '').' raised.'
                : $pack->name.' granted.',
            'data' => $this->payload($grant->fresh(['pack']), $tenant),
        ], 201);
    }

    /**
     * Revoke a grant. The entitlement goes immediately; an invoice already raised
     * stays on the books — revoking is not a credit note, and pretending otherwise
     * would quietly lose revenue records.
     */
    public function destroy(Request $request, TenantPack $assignment): JsonResponse
    {
        if ($assignment->status !== 'active') {
            return response()->json(['message' => 'This grant is already '.$assignment->status.'.'], 422);
        }

        $data = $request->validate(['reason' => ['sometimes', 'nullable', 'string', 'max:255']]);

        $tenant = Tenant::find($assignment->tenant_id);
        $packName = $assignment->pack?->name ?? 'pack';

        $this->packs->revoke($assignment, $data['reason'] ?? null);

        activity('central-billing')->causedBy($request->user())
            ->withProperties([
                'ip' => $request->ip(),
                'tenant_id' => $assignment->tenant_id,
                'grant_id' => $assignment->id,
                'reason' => $data['reason'] ?? null,
            ])
            ->event('pack-revoked')
            ->log("Operator revoked the {$packName} pack from \"".($tenant?->company_name ?? '?').'"');

        return response()->json([
            'message' => $packName.' revoked. Any invoice already raised for it is unchanged — refund it separately if that was the intent.',
            'data' => $this->payload($assignment->fresh(['pack']), $tenant),
        ]);
    }

    /**
     * Re-project a tenant's pack entitlement into its settings.
     *
     * The projection is written on every grant/revoke, so this is a repair tool —
     * for a workspace whose `enabled_modules` was edited by hand, or one restored
     * from a backup taken mid-change.
     */
    public function resync(Request $request, Tenant $tenant): JsonResponse
    {
        $this->packs->syncTenant($tenant->id);

        activity('central-billing')->causedBy($request->user())
            ->withProperties(['ip' => $request->ip(), 'tenant_id' => $tenant->id])
            ->event('pack-entitlement-resynced')
            ->log("Operator re-synced pack entitlement for \"{$tenant->company_name}\"");

        return $this->forTenant($tenant);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(TenantPack $g, ?Tenant $tenant): array
    {
        return [
            'id' => $g->id,
            'tenant_id' => $g->tenant_id,
            'tenant_name' => $tenant?->company_name,
            'pack_id' => $g->platform_pack_id,
            'pack_name' => $g->pack?->name,
            'pack_type' => $g->pack?->type,
            'modules' => $g->pack?->moduleKeys() ?? [],
            'limit_bumps' => $g->pack?->limitBumps() ?? [],
            'status' => $g->status,
            'source' => $g->source,
            'price' => $g->price,
            'currency' => $g->currency,
            'starts_at' => $g->starts_at?->toDateString(),
            'ends_at' => $g->ends_at?->toDateString(),
            'is_live' => $g->isLive(),
            'subscription_invoice_id' => $g->subscription_invoice_id,
            'invoice_number' => $g->invoice?->displayNumber(),
            'invoice_status' => $g->invoice?->status,
            'cancelled_at' => $g->cancelled_at?->toDateTimeString(),
            'notes' => $g->notes,
            'created_at' => $g->created_at?->toDateTimeString(),
        ];
    }
}
