<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Thin server-side wrapper around the Google Maps Platform web services
 * (Places Autocomplete, Place Details, Geocoding). The API key lives only
 * in .env (GOOGLE_MAPS_API_KEY) and never reaches the browser — the
 * frontend consumes these through the /api/google/* proxy endpoints.
 */
class GoogleMapsService
{
    private const BASE = 'https://maps.googleapis.com/maps/api';

    public function isConfigured(): bool
    {
        return (string) config('services.google_maps.key') !== '';
    }

    /**
     * Places Autocomplete: returns [['place_id' =>, 'description' =>,
     * 'main_text' =>, 'secondary_text' =>], ...].
     *
     * @param string|null $country ISO-3166-1 alpha-2 restriction, e.g. "in"
     * @param string|null $sessionToken Groups autocomplete + details calls for billing
     */
    public function autocomplete(string $input, ?string $country = null, ?string $sessionToken = null): array
    {
        $params = [
            'input' => $input,
            'key' => $this->key(),
        ];
        if ($country) {
            $params['components'] = 'country:' . strtolower($country);
        }
        if ($sessionToken) {
            $params['sessiontoken'] = $sessionToken;
        }

        $data = $this->call('/place/autocomplete/json', $params);

        return array_map(fn (array $p) => [
            'place_id' => $p['place_id'] ?? '',
            'description' => $p['description'] ?? '',
            'main_text' => $p['structured_formatting']['main_text'] ?? ($p['description'] ?? ''),
            'secondary_text' => $p['structured_formatting']['secondary_text'] ?? '',
        ], $data['predictions'] ?? []);
    }

    /**
     * Place Details, normalised into the shape the address forms consume.
     * Results are cached (place data is not tenant-specific). Falls back to
     * the Geocoding API for the postal code when Google omits it from the
     * place's address components.
     */
    public function placeDetails(string $placeId, ?string $sessionToken = null): array
    {
        return Cache::remember("gmaps_place_{$placeId}", now()->addDay(), function () use ($placeId, $sessionToken) {
            $params = [
                'place_id' => $placeId,
                'fields' => 'formatted_address,geometry/location,address_component,place_id',
                'key' => $this->key(),
            ];
            if ($sessionToken) {
                $params['sessiontoken'] = $sessionToken;
            }

            $data = $this->call('/place/details/json', $params);
            $result = $data['result'] ?? null;
            if (!$result) {
                throw new RuntimeException('No result found for this place.');
            }

            $parsed = $this->parseAddressComponents($result['address_components'] ?? []);
            $lat = $result['geometry']['location']['lat'] ?? null;
            $lng = $result['geometry']['location']['lng'] ?? null;

            if ($parsed['postal_code'] === '' && $lat !== null && $lng !== null) {
                $parsed['postal_code'] = $this->postalCodeFromLatLng((float) $lat, (float) $lng);
            }

            return [
                'place_id' => $result['place_id'] ?? $placeId,
                'formatted_address' => $result['formatted_address'] ?? '',
                'country' => $parsed['country'],
                'state' => $parsed['state'],
                'city' => $parsed['city'],
                'area' => $parsed['area'],
                'postal_code' => $parsed['postal_code'],
                'latitude' => $lat,
                'longitude' => $lng,
            ];
        });
    }

    /** Reverse-geocode a coordinate and pull out the postal code, if any. */
    private function postalCodeFromLatLng(float $lat, float $lng): string
    {
        try {
            $data = $this->call('/geocode/json', [
                'latlng' => "{$lat},{$lng}",
                'result_type' => 'postal_code',
                'key' => $this->key(),
            ]);
            foreach ($data['results'] ?? [] as $r) {
                foreach ($r['address_components'] ?? [] as $c) {
                    if (in_array('postal_code', $c['types'] ?? [], true)) {
                        return $c['long_name'] ?? '';
                    }
                }
            }
        } catch (RuntimeException) {
            // Postal code is a best-effort enrichment — never fail details for it.
        }

        return '';
    }

    private function parseAddressComponents(array $components): array
    {
        $get = function (array $types, bool $short = false) use ($components): string {
            foreach ($types as $type) {
                foreach ($components as $c) {
                    if (in_array($type, $c['types'] ?? [], true)) {
                        return ($short ? $c['short_name'] : $c['long_name']) ?? '';
                    }
                }
            }

            return '';
        };

        return [
            'country' => $get(['country']),
            'state' => $get(['administrative_area_level_1']),
            'city' => $get(['locality', 'administrative_area_level_2', 'administrative_area_level_3']),
            'area' => $get(['sublocality_level_1', 'sublocality', 'neighborhood', 'route']),
            'postal_code' => $get(['postal_code']),
        ];
    }

    private function call(string $path, array $params): array
    {
        try {
            $response = Http::timeout(10)->get(self::BASE . $path, $params);
        } catch (ConnectionException $e) {
            // Never bubble the raw cURL message up — the request URL in it
            // contains the API key. Log it (sans key) for diagnosis instead.
            Log::warning('Google Maps API connection failed: ' . strtok($e->getMessage(), '?'));

            throw new RuntimeException('Could not reach Google Maps API (network or SSL issue on the server).');
        }

        if ($response->failed()) {
            throw new RuntimeException('Google Maps API is unavailable right now.');
        }

        $data = $response->json();
        $status = $data['status'] ?? 'UNKNOWN_ERROR';

        if (!in_array($status, ['OK', 'ZERO_RESULTS'], true)) {
            throw new RuntimeException(match ($status) {
                'INVALID_REQUEST' => 'Invalid place lookup request.',
                'NOT_FOUND' => 'No result found for this place.',
                'REQUEST_DENIED' => 'Google Maps API request was denied — check the API key and its restrictions.',
                'OVER_QUERY_LIMIT' => 'Google Maps API quota exceeded. Try again later.',
                default => 'Google Maps API error: ' . $status,
            });
        }

        return $data;
    }

    private function key(): string
    {
        $key = (string) config('services.google_maps.key');
        if ($key === '') {
            throw new RuntimeException('Google Maps API key is not configured.');
        }

        return $key;
    }
}
