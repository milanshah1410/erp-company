<?php

namespace App\Http\Controllers\Central\Ops;

use App\Http\Controllers\Controller;
use App\Models\FeatureFlag;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Ops panel — platform feature flags. Listing is gated by feature-flags.view;
 * create/update/delete by feature-flags.edit (routes/api.php).
 */
class FeatureFlagController extends Controller
{
    public function index()
    {
        return response()->json(['data' => FeatureFlag::orderBy('key')->get()]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9]+(?:[-_.][a-z0-9]+)*$/', 'unique:feature_flags,key'],
            'label' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:255'],
            'enabled' => ['boolean'],
        ]);
        $data['updated_by'] = $request->user()->name;

        $flag = FeatureFlag::create($data);

        return response()->json(['data' => $flag], 201);
    }

    public function update(Request $request, FeatureFlag $featureFlag)
    {
        $data = $request->validate([
            'key' => ['sometimes', 'string', 'max:100', 'regex:/^[a-z0-9]+(?:[-_.][a-z0-9]+)*$/', Rule::unique('feature_flags', 'key')->ignore($featureFlag->id)],
            'label' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:255'],
            'enabled' => ['sometimes', 'boolean'],
        ]);
        $data['updated_by'] = $request->user()->name;

        $featureFlag->update($data);

        return response()->json(['data' => $featureFlag->fresh()]);
    }

    public function destroy(FeatureFlag $featureFlag)
    {
        $featureFlag->delete();

        return response()->json(['message' => 'Feature flag deleted.']);
    }
}
