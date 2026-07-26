<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\MenuItem;
use App\Models\Plan;
use App\Support\Modules;
use Illuminate\Http\JsonResponse;

class PlanController extends Controller
{
    /**
     * Public plan catalogue. `modules` is resolved through the plan (so '*'
     * arrives expanded) and `modules_all` tells the wizard whether the plan
     * places no restriction at all — that is what unlocks the full grid.
     */
    public function index(): JsonResponse
    {
        $plans = Plan::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->map(fn (Plan $p) => [
                'slug' => $p->slug,
                'name' => $p->name,
                'price' => (float) $p->price,
                'currency' => $p->currency,
                'billing_cycle' => $p->billing_cycle,
                'is_custom' => $p->is_custom,
                'feature_limits' => $p->feature_limits,
                'modules' => $p->allowedModules(),
                'modules_all' => $p->allowsAllModules(),
            ]);

        return response()->json(['data' => $plans]);
    }

    /**
     * The module catalogue rendered by the onboarding wizard (step 3), each entry
     * carrying the menu screens it unlocks so the wizard can show "this package
     * gives you these menus" instead of bare module names. Labels are read from
     * the global menu catalogue, so the listing can never drift from the nav.
     */
    public function modules(): JsonResponse
    {
        $rows = MenuItem::whereNull('tenant_id')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();
        $byParent = $rows->groupBy('parent_id');

        // Every clickable screen under a row (groups themselves are not screens).
        $screens = function (MenuItem $row) use (&$screens, $byParent): array {
            $children = $byParent->get($row->id, collect());
            if ($children->isEmpty()) {
                return $row->route ? [$row->label] : [];
            }

            $out = [];
            foreach ($children as $child) {
                $out = array_merge($out, $screens($child));
            }

            return $out;
        };

        $menus = [];
        foreach ($rows->whereNotNull('module') as $row) {
            $menus[$row->module] = array_values(array_unique(
                array_merge($menus[$row->module] ?? [], $screens($row)),
            ));
        }

        $options = array_map(
            static fn (array $module) => $module + ['menus' => $menus[$module['key']] ?? []],
            Modules::options(),
        );

        return response()->json(['data' => $options]);
    }
}
