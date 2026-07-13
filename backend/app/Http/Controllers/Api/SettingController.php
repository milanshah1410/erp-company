<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SettingController extends Controller
{
    /** All settings as a flat key => value map, defaults filled in for anything unset. */
    public function index()
    {
        return response()->json([
            'data' => Setting::allWithDefaults(),
        ]);
    }

    /** Update one or more known settings. Unknown keys are rejected. */
    public function update(Request $request)
    {
        $rules = [
            'costing_method' => ['sometimes', 'required', Rule::in(Setting::COSTING_METHODS)],
            'purchase_approval_threshold' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'sales_payment_term_days' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'financial_year_start_month' => ['sometimes', 'required', 'integer', 'min:1', 'max:12'],
            'currency_code' => ['sometimes', 'required', 'string', 'max:10'],
            'currency_symbol' => ['sometimes', 'required', 'string', 'max:5'],
            'default_tax_percent' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
        ];
        foreach (array_keys(Setting::NUMBER_PREFIX_DEFAULTS) as $key) {
            $rules[$key] = ['sometimes', 'required', 'string', 'max:10', 'alpha_dash'];
        }

        $validated = $request->validate($rules);

        foreach ($validated as $key => $value) {
            Setting::set($key, $value);
        }

        return response()->json([
            'data' => Setting::allWithDefaults(),
        ]);
    }
}
