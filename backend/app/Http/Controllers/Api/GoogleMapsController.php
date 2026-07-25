<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\GoogleMapsService;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Proxy for Google Maps Platform lookups so the API key stays server-side.
 * Consumed by GoogleAddressAutocomplete.vue on address forms.
 */
class GoogleMapsController extends Controller
{
    public function __construct(private readonly GoogleMapsService $maps)
    {
    }

    /** GET /api/google/place-autocomplete?input=&country=&session_token= */
    public function autocomplete(Request $request)
    {
        $data = $request->validate([
            'input' => ['required', 'string', 'min:3', 'max:200'],
            'country' => ['nullable', 'string', 'size:2'],
            'session_token' => ['nullable', 'string', 'max:64'],
        ]);

        if (!$this->maps->isConfigured()) {
            return response()->json(['message' => 'Address lookup is not configured.'], 503);
        }

        try {
            $predictions = $this->maps->autocomplete($data['input'], $data['country'] ?? null, $data['session_token'] ?? null);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        return response()->json(['data' => $predictions]);
    }

    /** GET /api/google/place-details?place_id=&session_token= */
    public function placeDetails(Request $request)
    {
        $data = $request->validate([
            'place_id' => ['required', 'string', 'max:512'],
            'session_token' => ['nullable', 'string', 'max:64'],
        ]);

        if (!$this->maps->isConfigured()) {
            return response()->json(['message' => 'Address lookup is not configured.'], 503);
        }

        try {
            $details = $this->maps->placeDetails($data['place_id'], $data['session_token'] ?? null);
        } catch (RuntimeException $e) {
            $notFound = str_contains($e->getMessage(), 'No result');

            return response()->json(['message' => $e->getMessage()], $notFound ? 404 : 502);
        }

        return response()->json(['data' => $details]);
    }
}
