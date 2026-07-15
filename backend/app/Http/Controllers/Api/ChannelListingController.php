<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ChannelListingResource;
use App\Models\ChannelIntegration;
use App\Models\ChannelListing;
use App\Services\Ecommerce\ConnectorFactory;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Product ↔ listing mapping (SRS §15): links a local product/variant to its
 * identifier on a channel so inventory pushes and order imports can translate
 * between the ERP SKU and the marketplace's SKU/listing id.
 */
class ChannelListingController extends Controller
{
    public function __construct(private readonly ConnectorFactory $factory) {}

    public function index(Request $request)
    {
        $query = ChannelListing::query()
            ->with(['product:id,sku,name', 'variant:id,sku,product_id'])
            ->orderByDesc('id');

        if ($request->filled('channel_integration_id')) {
            $query->where('channel_integration_id', $request->query('channel_integration_id'));
        }
        if ($request->filled('product_id')) {
            $query->where('product_id', $request->query('product_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        $perPage = min((int) $request->query('per_page', 20), 100);

        return ChannelListingResource::collection($query->paginate($perPage));
    }

    public function store(Request $request)
    {
        $data = $this->validatePayload($request, creating: true);

        $listing = ChannelListing::create($data);

        return (new ChannelListingResource($listing->load(['product:id,sku,name', 'variant:id,sku,product_id'])))
            ->response()->setStatusCode(201);
    }

    public function update(Request $request, ChannelListing $channelListing)
    {
        $data = $this->validatePayload($request, creating: false);
        $channelListing->update($data);

        return new ChannelListingResource($channelListing->load(['product:id,sku,name', 'variant:id,sku,product_id']));
    }

    public function destroy(ChannelListing $channelListing)
    {
        $channelListing->delete();

        return response()->json(['message' => 'Listing mapping removed.']);
    }

    /** Fetch the channel's remote listings for the mapping picker. */
    public function remote(ChannelIntegration $ecommerceIntegration)
    {
        try {
            $listings = $this->factory->make($ecommerceIntegration)->fetchListings();
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $listings]);
    }

    private function validatePayload(Request $request, bool $creating): array
    {
        return $request->validate([
            'channel_integration_id' => [$creating ? 'required' : 'sometimes', 'integer', 'exists:channel_integrations,id'],
            'product_id' => [$creating ? 'required' : 'sometimes', 'integer', 'exists:products,id'],
            'variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'external_id' => ['nullable', 'string', 'max:191'],
            'external_sku' => ['nullable', 'string', 'max:191'],
            'listing_title' => ['nullable', 'string', 'max:255'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ]);
    }
}
