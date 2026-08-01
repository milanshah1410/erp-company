<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\IndustryTemplate;
use App\Models\Tenant;
use App\Support\IndustryTemplates;
use App\Support\Modules;
use App\Support\ShopProfiles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The industry catalogue, as the SaaS owner maintains it (Volume 11 §"Industry
 * Templates"). Guarded by the `industries.*` central permissions.
 *
 * A template is packaging: a vertical's label, the trades it offers and the
 * modules a workspace in it starts with. Editing one never touches a live
 * workspace — provisioning reads the template once, at signup, and the tenant
 * owns its module selection from then on. That is deliberate: an owner must be
 * able to re-curate what they sell tomorrow without reconfiguring everyone who
 * bought yesterday.
 *
 * Two guards on destructive edits:
 *
 *  - a template with tenants on it cannot be deleted (they would be left
 *    pointing at nothing); deactivate it instead, which hides it from new
 *    signups and leaves history intact.
 *  - system templates — the seeded catalogue and any plugin-backed vertical —
 *    cannot be deleted at all. A plugin still ships its tables and screens; a
 *    row disappearing would not remove them, only hide them from the picker.
 */
class IndustryTemplateController extends Controller
{
    public function index(): JsonResponse
    {
        $templates = IndustryTemplate::query()->orderBy('sort_order')->orderBy('id')->get();

        // How many workspaces each vertical actually won — the number that makes
        // this screen a business view rather than a config form.
        $counts = Tenant::query()
            ->selectRaw('industry, count(*) as aggregate')
            ->whereNotNull('industry')
            ->groupBy('industry')
            ->pluck('aggregate', 'industry');

        return response()->json([
            'data' => $templates->map(fn (IndustryTemplate $t) => [
                'id' => $t->id,
                'key' => $t->key,
                'label' => $t->label,
                'icon' => $t->icon,
                'description' => $t->description,
                'modules' => $t->moduleKeys(),
                // Curated keys as stored, plus what they resolve to right now —
                // a plugin-backed row stores none and reports the plugin's.
                'trades' => $t->tradeKeys(),
                'plugin_key' => $t->plugin_key,
                'is_system' => $t->is_system,
                'is_active' => $t->is_active,
                'sort_order' => $t->sort_order,
                'tenants_count' => (int) ($counts[$t->key] ?? 0),
                /**
                 * What this vertical actually delivers, trade by trade.
                 *
                 * An operator selling "Restaurant & Food" needs to know that a
                 * bakery buyer gets cake orders but not the menu card, and until
                 * this was surfaced the only place that was written down was a
                 * plugin's PHP. Derived on every read from the plugin's own
                 * declaration, so it can never drift from what ships.
                 */
                'trade_coverage' => $this->coverageFor($t),
            ])->values(),
            // Reference lists so the editor renders without extra round-trips.
            'available_trades' => ShopProfiles::options(),
            'available_modules' => Modules::options(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateData($request);

        // Key is derived, not typed: it is a stable identifier stored on every
        // tenant provisioned under this vertical.
        $data['key'] = $this->uniqueKey($data['label']);
        $data['is_system'] = false;
        $data['sort_order'] ??= (int) IndustryTemplate::max('sort_order') + 10;

        $template = IndustryTemplate::create($data);

        activity('central-industries')->causedBy($request->user())
            ->withProperties(['ip' => $request->ip(), 'template_id' => $template->id])
            ->event('created')->log("Operator created industry template \"{$template->label}\"");

        return response()->json(['data' => $template], 201);
    }

    public function update(Request $request, IndustryTemplate $industryTemplate): JsonResponse
    {
        $data = $this->validateData($request, $industryTemplate);

        // A plugin owns its vertical's trades; accepting a curated list here
        // would store something tradeKeys() then ignores.
        if ($industryTemplate->plugin_key) {
            unset($data['trades']);
        }

        $industryTemplate->update($data);

        activity('central-industries')->causedBy($request->user())
            ->withProperties(['ip' => $request->ip(), 'template_id' => $industryTemplate->id, 'changes' => array_keys($data)])
            ->event('updated')->log("Operator updated industry template \"{$industryTemplate->label}\"");

        return response()->json(['data' => $industryTemplate->fresh()]);
    }

    public function destroy(Request $request, IndustryTemplate $industryTemplate): JsonResponse
    {
        if ($industryTemplate->is_system) {
            return response()->json([
                'message' => 'Built-in industry templates cannot be deleted — deactivate it to hide it from new signups.',
            ], 422);
        }

        $inUse = Tenant::where('industry', $industryTemplate->key)->count();
        if ($inUse > 0) {
            return response()->json([
                'message' => "{$inUse} workspace(s) are classified under this industry — deactivate it instead of deleting.",
            ], 422);
        }

        $label = $industryTemplate->label;
        $industryTemplate->delete();

        activity('central-industries')->causedBy($request->user())
            ->withProperties(['ip' => $request->ip(), 'label' => $label])
            ->event('deleted')->log("Operator deleted industry template \"{$label}\"");

        return response()->json(['message' => 'Industry template deleted.']);
    }

    /**
     * Show/hide a vertical on the signup picker without touching its definition.
     */
    public function toggle(Request $request, IndustryTemplate $industryTemplate): JsonResponse
    {
        $industryTemplate->update(['is_active' => ! $industryTemplate->is_active]);

        activity('central-industries')->causedBy($request->user())
            ->withProperties(['ip' => $request->ip(), 'template_id' => $industryTemplate->id, 'is_active' => $industryTemplate->is_active])
            ->event($industryTemplate->is_active ? 'activated' : 'deactivated')
            ->log("Operator ".($industryTemplate->is_active ? 'activated' : 'deactivated')." industry template \"{$industryTemplate->label}\"");

        return response()->json(['data' => $industryTemplate->fresh()]);
    }

    /**
     * Feature coverage for each trade a vertical offers.
     *
     * Reads the trades the template *resolves* to rather than the keys it stores,
     * so a plugin-backed row (which stores none) reports its plugin's trades — the
     * same resolution the signup picker uses, which is what makes this screen and
     * that picker agree.
     *
     * A trade no plugin declares reports zero features rather than being dropped:
     * "this hand-written preset makes no capability claims" is the honest answer,
     * and hiding the row would read as the trade not being sold.
     *
     * @return list<array{
     *     key: string, label: string, available: int, planned: int,
     *     features: array<string, string>
     * }>
     */
    private function coverageFor(IndustryTemplate $template): array
    {
        $presets = ShopProfiles::all();

        $out = [];

        foreach ($template->tradeKeys() as $key) {
            $features = ShopProfiles::featuresFor($key);

            $out[] = [
                'key' => $key,
                'label' => $presets[$key]['label'] ?? $key,
                'available' => count(array_filter($features, static fn ($s) => $s === 'available')),
                'planned' => count(array_filter($features, static fn ($s) => $s !== 'available')),
                'features' => $features,
            ];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function validateData(Request $request, ?IndustryTemplate $template = null): array
    {
        $sometimes = $template ? 'sometimes' : 'required';

        $data = $request->validate([
            'label' => [$sometimes, 'string', 'max:100'],
            'icon' => ['nullable', 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:255'],
            'modules' => ['nullable', 'array'],
            'modules.*' => ['string', Rule::in(Modules::keys())],
            'trades' => ['nullable', 'array'],
            'trades.*' => ['string', Rule::in(ShopProfiles::keys())],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        // Drop absent keys so a partial update doesn't null out what it omitted.
        return array_filter(
            $data,
            static fn ($value, $key) => $request->has($key),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /** Slug of the label, suffixed if that key is taken. */
    private function uniqueKey(string $label): string
    {
        $base = Str::slug($label, '_') ?: 'industry';
        $base = Str::limit($base, 40, '');

        $key = $base;
        $n = 2;
        while (in_array($key, IndustryTemplates::keys(), true)) {
            $key = $base.'_'.$n++;
        }

        return $key;
    }
}
