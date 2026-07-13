<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use Illuminate\Http\JsonResponse;

class PlanController extends Controller
{
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
            ]);

        return response()->json(['data' => $plans]);
    }
}
