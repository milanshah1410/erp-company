<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\RendersDocumentPdf;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\SalesItem;
use App\Models\SalesOrder;
use App\Services\BarcodeService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Printer Testing: renders the shipping print templates on throw-away sample
 * data so a shop can verify its hardware (paper size, margins, label pitch,
 * roll width) without creating a real document.
 *
 * Three templates are covered:
 *   - A4 Invoice     → documents.invoice, the same Blade real invoices use
 *   - Barcode Label  → labels.print, the same sheet the label printer gets
 *   - Thermal Invoice→ rendered client-side (utils/posReceipt.ts), because the
 *                      58/80 mm receipt is HTML/ESC-POS driven by the terminal's
 *                      own device settings rather than by the server.
 *
 * Nothing here touches the database: every model below is instantiated unsaved
 * and has its relations pre-set, so the Blade templates run exactly as they do
 * for live documents while the test prints leave no trace.
 */
class PrinterTestController extends Controller
{
    use RendersDocumentPdf;

    public function __construct(private BarcodeService $barcodes) {}

    /**
     * Sample A4 tax invoice. `lines` drives how many item rows are printed so a
     * multi-page run (repeating footer / signature block) can be tested too.
     */
    public function a4Invoice(Request $request)
    {
        $data = $request->validate([
            'paper' => ['nullable', Rule::in(['a4', 'letter', 'a5'])],
            'lines' => ['nullable', 'integer', 'min:1', 'max:60'],
        ]);

        $invoice = $this->sampleInvoice((int) ($data['lines'] ?? 5));

        return $this->renderDocument(
            $request,
            'documents.invoice',
            ['invoice' => $invoice, 'docSubtitle' => 'PRINTER TEST — NOT A VALID INVOICE'],
            'printer-test-a4-invoice.pdf',
            $data['paper'] ?? 'a4',
        );
    }

    /**
     * Sample label sheet in any of the four §21 formats. `count` repeats the
     * label so label pitch and cut lines can be checked across a full sheet.
     */
    public function labels(Request $request)
    {
        $data = $request->validate([
            'template' => ['nullable', Rule::in(['barcode', 'qr', 'sticker', 'price_tag'])],
            'label_type' => ['nullable', Rule::in(['code128', 'qr'])],
            'count' => ['nullable', 'integer', 'min:1', 'max:200'],
            'value' => ['nullable', 'string', 'max:64'],
            'show_company' => ['nullable', 'boolean'],
            'show_price' => ['nullable', 'boolean'],
        ]);

        $template = $data['template'] ?? 'barcode';
        $symbology = match ($template) {
            'qr' => 'qr',
            'barcode' => 'code128',
            default => $data['label_type'] ?? 'code128',
        };

        // CODE128 can encode the whole ASCII range, so the placeholder value is
        // safe for both symbologies; the user may override it to test a real code.
        $value = trim($data['value'] ?? '') ?: '8901234567890';

        $company = $this->documentCompany($request);
        $showPrice = $request->boolean('show_price', true);

        $label = [
            'line1' => 'Printer Test Product',
            'line2' => 'TEST-SKU-001',
            'value' => $value,
            'price' => $showPrice ? 499.00 : null,
            'category' => 'Test Category',
            'brand' => 'Test Brand',
            'image' => $symbology === 'qr'
                ? $this->barcodes->qrDataUri($value, 220)
                : $this->barcodes->code128DataUri($value),
        ];

        $pdf = Pdf::loadView('labels.print', [
            'labels' => array_fill(0, (int) ($data['count'] ?? 12), $label),
            'template' => $template,
            'symbology' => $symbology,
            'company' => $request->boolean('show_company', true) ? $company : null,
            'currency' => $company['currency'],
        ])->setPaper('a4');

        return $pdf->stream('printer-test-labels.pdf');
    }

    /**
     * An unsaved invoice + sales order + items graph that satisfies everything
     * documents.invoice reads. Relations are set explicitly so the template
     * never falls through to a database query.
     */
    private function sampleInvoice(int $lineCount): Invoice
    {
        $catalogue = [
            ['name' => 'Cotton Shirting Fabric', 'sku' => 'FAB-CTN-001', 'qty' => 12.5, 'rate' => 240.00, 'tax' => 5.0],
            ['name' => 'Silk Saree — Zari Border', 'sku' => 'SAR-SLK-114', 'qty' => 2, 'rate' => 4850.00, 'tax' => 12.0],
            ['name' => 'Denim Roll 3/1 Twill', 'sku' => 'FAB-DNM-220', 'qty' => 30, 'rate' => 310.00, 'tax' => 5.0],
            ['name' => 'Polyester Lining', 'sku' => 'FAB-PLY-009', 'qty' => 45.75, 'rate' => 68.50, 'tax' => 5.0],
            ['name' => 'Embroidery Thread Box (50 pcs)', 'sku' => 'ACC-THR-050', 'qty' => 4, 'rate' => 1250.00, 'tax' => 18.0],
            ['name' => 'Brass Buttons — Antique Finish', 'sku' => 'ACC-BTN-771', 'qty' => 500, 'rate' => 3.20, 'tax' => 18.0],
        ];

        $items = collect();
        $subtotal = 0.0;
        $taxTotal = 0.0;

        for ($i = 0; $i < $lineCount; $i++) {
            $row = $catalogue[$i % count($catalogue)];

            $lineBase = round($row['qty'] * $row['rate'], 2);
            $lineTax = round($lineBase * $row['tax'] / 100, 2);
            $subtotal += $lineBase;
            $taxTotal += $lineTax;

            $product = new Product(['name' => $row['name'], 'sku' => $row['sku']]);

            $item = new SalesItem([
                'quantity' => $row['qty'],
                'unit_price' => $row['rate'],
                'tax_percent' => $row['tax'],
                'tax_amount' => $lineTax,
                'line_total' => round($lineBase + $lineTax, 2),
            ]);
            $item->setRelation('product', $product);
            $item->setRelation('variant', null);

            $items->push($item);
        }

        $subtotal = round($subtotal, 2);
        $taxTotal = round($taxTotal, 2);
        $gross = $subtotal + $taxTotal;
        $roundOff = round(round($gross) - $gross, 2);
        $total = round($gross + $roundOff, 2);

        $customer = new Customer([
            'name' => 'Printer Test Customer',
            'company_name' => 'Test Traders Pvt. Ltd.',
            'address' => '12, Sample Market, Ring Road',
            'city' => 'Surat',
            'state' => 'Gujarat',
            'pincode' => '395002',
            'phone' => '90000 00000',
            'gst_number' => '24AAAAA0000A1Z5',
        ]);

        $so = new SalesOrder([
            'so_number' => 'SO-TEST-0001',
            'order_date' => now()->subDays(2),
            'subtotal' => $subtotal,
            'item_discount_total' => 0,
            'discount_amount' => 0,
            'tax_amount' => $taxTotal,
            'round_off' => $roundOff,
            'total' => $total,
        ]);
        $so->setRelation('items', $items);

        $invoice = new Invoice([
            'invoice_number' => 'INV-TEST-0001',
            'invoice_date' => now(),
            'due_date' => now()->addDays(30),
            'total' => $total,
            'paid_amount' => 0,
            'credited_amount' => 0,
            'status' => 'unpaid',
        ]);
        $invoice->setRelation('salesOrder', $so);
        $invoice->setRelation('customer', $customer);
        $invoice->setRelation('broker', null);

        return $invoice;
    }
}
