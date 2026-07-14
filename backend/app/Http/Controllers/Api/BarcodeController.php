<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PrintLabelsRequest;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\BarcodeService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BarcodeController extends Controller
{
    public function __construct(private readonly BarcodeService $barcodes) {}

    /** Render an ad-hoc value as a barcode/QR PNG (e.g. live preview while editing a product). */
    public function render(Request $request)
    {
        $data = $request->validate([
            'value' => ['required', 'string', 'max:255'],
            'type' => ['nullable', Rule::in(['code128', 'qr'])],
        ]);

        $png = $data['type'] === 'qr'
            ? $this->barcodes->qrPng($data['value'])
            : $this->barcodes->code128Png($data['value']);

        return response($png, 200)->header('Content-Type', 'image/png');
    }

    /** Render the stored barcode value for a product as a PNG. */
    public function productImage(Request $request, Product $product)
    {
        if (empty($product->barcode)) {
            abort(404, 'This product has no barcode value.');
        }

        return $this->imageResponse($request, $product->barcode);
    }

    /** Render the stored barcode value for a product variant as a PNG. */
    public function variantImage(Request $request, Product $product, ProductVariant $variant)
    {
        if ($variant->product_id !== $product->id) {
            abort(404, 'Variant does not belong to this product.');
        }
        if (empty($variant->barcode)) {
            abort(404, 'This variant has no barcode value.');
        }

        return $this->imageResponse($request, $variant->barcode);
    }

    private function imageResponse(Request $request, string $value)
    {
        $type = $request->query('type', 'code128');
        $png = $type === 'qr' ? $this->barcodes->qrPng($value) : $this->barcodes->code128Png($value);

        return response($png, 200)->header('Content-Type', 'image/png');
    }

    /** Resolve a scanned code (product/variant SKU, barcode or RFID tag) to the item it identifies. */
    public function lookup(Request $request)
    {
        $code = trim((string) $request->query('code', ''));
        if ($code === '') {
            throw ValidationException::withMessages(['code' => 'A code is required.']);
        }

        $variant = ProductVariant::with('product.category', 'product.brand', 'product.unit')
            ->where(fn ($q) => $q->where('barcode', $code)->orWhere('sku', $code)->orWhere('rfid_tag', $code))
            ->first();

        if ($variant) {
            return response()->json([
                'type' => 'variant',
                'variant' => [
                    'id' => $variant->id,
                    'sku' => $variant->sku,
                    'barcode' => $variant->barcode,
                    'rfid_tag' => $variant->rfid_tag,
                    'name' => $variant->name,
                    'selling_price' => $variant->selling_price,
                ],
                'product' => $this->productSummary($variant->product),
            ]);
        }

        $product = Product::with('category', 'brand', 'unit', 'stocks')
            ->where(fn ($q) => $q->where('barcode', $code)->orWhere('sku', $code)->orWhere('rfid_tag', $code))
            ->first();

        if ($product) {
            return response()->json([
                'type' => 'product',
                'product' => $this->productSummary($product),
            ]);
        }

        return response()->json(['message' => 'No product or variant matches this code.'], 404);
    }

    private function productSummary(Product $product): array
    {
        return [
            'id' => $product->id,
            'sku' => $product->sku,
            'barcode' => $product->barcode,
            'rfid_tag' => $product->rfid_tag,
            'name' => $product->name,
            'category' => $product->category?->name,
            'brand' => $product->brand?->name,
            'unit' => $product->unit?->short_name,
            'selling_price' => $product->selling_price,
            'total_stock' => $product->relationLoaded('stocks') ? (float) $product->stocks->sum('quantity') : null,
        ];
    }

    /** Build a printable PDF of barcode/QR labels for the selected products/variants. */
    public function printLabels(PrintLabelsRequest $request)
    {
        $labelType = $request->input('label_type', 'code128');
        $labels = [];

        foreach ($request->input('items') as $item) {
            if ($item['type'] === 'variant') {
                $variant = ProductVariant::with('product')->find($item['id']);
                if (! $variant || empty($variant->barcode)) {
                    continue;
                }
                $line1 = $variant->product->name;
                $line2 = $variant->name ?: $variant->sku;
                $value = $variant->barcode;
                $price = $variant->selling_price ?? $variant->product->selling_price;
            } else {
                $product = Product::find($item['id']);
                if (! $product || empty($product->barcode)) {
                    continue;
                }
                $line1 = $product->name;
                $line2 = $product->sku;
                $value = $product->barcode;
                $price = $product->selling_price;
            }

            $image = $labelType === 'qr'
                ? $this->barcodes->qrDataUri($value, 200)
                : $this->barcodes->code128DataUri($value);

            for ($i = 0; $i < $item['quantity']; $i++) {
                $labels[] = [
                    'line1' => $line1,
                    'line2' => $line2,
                    'value' => $value,
                    'price' => $price,
                    'image' => $image,
                ];
            }
        }

        if (empty($labels)) {
            throw ValidationException::withMessages(['items' => 'None of the selected items have a barcode value to print.']);
        }

        $pdf = Pdf::loadView('labels.print', ['labels' => $labels])->setPaper('a4');

        return $pdf->stream('labels.pdf');
    }

    /**
     * Bind a scanned RFID tag to a product or variant. Enforces global (per-tenant)
     * uniqueness across both products and variants so a tag resolves unambiguously
     * in lookup(). Handy for scan-to-assign workflows with an HID RFID reader.
     */
    public function assignRfid(Request $request)
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['product', 'variant'])],
            'id' => ['required', 'integer'],
            'rfid_tag' => ['required', 'string', 'max:255'],
        ]);

        $tag = trim($data['rfid_tag']);

        // A tag may already sit on another product or variant — reject either collision.
        $productOwner = Product::where('rfid_tag', $tag)->first();
        $variantOwner = ProductVariant::where('rfid_tag', $tag)->first();

        if ($data['type'] === 'variant') {
            $variant = ProductVariant::with('product')->findOrFail($data['id']);

            if ($productOwner || ($variantOwner && $variantOwner->id !== $variant->id)) {
                throw ValidationException::withMessages(['rfid_tag' => 'This RFID tag is already assigned to another item.']);
            }

            $variant->update(['rfid_tag' => $tag]);

            return response()->json([
                'type' => 'variant',
                'id' => $variant->id,
                'rfid_tag' => $variant->rfid_tag,
                'name' => trim($variant->product->name.' — '.($variant->name ?: $variant->sku)),
            ]);
        }

        $product = Product::findOrFail($data['id']);

        if ($variantOwner || ($productOwner && $productOwner->id !== $product->id)) {
            throw ValidationException::withMessages(['rfid_tag' => 'This RFID tag is already assigned to another item.']);
        }

        $product->update(['rfid_tag' => $tag]);

        return response()->json([
            'type' => 'product',
            'id' => $product->id,
            'rfid_tag' => $product->rfid_tag,
            'name' => $product->name,
        ]);
    }
}
