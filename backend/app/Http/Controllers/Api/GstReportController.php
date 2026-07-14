<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ExportsReports;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\GstRegistration;
use App\Models\PurchaseInvoiceItem;
use App\Models\SalesItem;
use Illuminate\Http\Request;

/**
 * Consolidated GST reports:
 *  - summary  → GSTR-3B style: outward tax, inward ITC and net payable.
 *  - gstr1    → outward supplies split B2B / B2C, HSN + rate wise, with the
 *               CGST/SGST vs IGST split derived from place of supply.
 *
 * Tax is stored as a single tax_percent / tax_amount per line (no statutory
 * CGST/SGST/IGST columns exist), so the split is inferred: a sale to a customer
 * in the company's home state is intra-state (CGST = SGST = tax/2), otherwise
 * inter-state (IGST = tax). Unknown customer state defaults to intra-state.
 */
class GstReportController extends Controller
{
    use ExportsReports;

    /** GSTR-3B summary: output tax vs input credit vs net payable. */
    public function summary(Request $request)
    {
        [$from, $to] = [$request->query('date_from'), $request->query('date_to')];

        $out = $this->salesTaxTotals($from, $to);
        $in = $this->purchaseTaxTotals($from, $to);
        $net = round($out['tax'] - $in['tax'], 2);

        $rows = [
            ['particulars' => 'Outward supplies (Sales) — output tax', 'taxable_value' => $out['taxable'], 'tax_amount' => $out['tax']],
            ['particulars' => 'Inward supplies (Purchases) — input tax credit', 'taxable_value' => $in['taxable'], 'tax_amount' => $in['tax']],
            ['particulars' => $net >= 0 ? 'Net GST payable' : 'Net ITC carried forward', 'taxable_value' => null, 'tax_amount' => $net],
        ];

        return $this->exportResponse($request, $rows, [
            'particulars' => 'Particulars', 'taxable_value' => 'Taxable Value', 'tax_amount' => 'Tax Amount',
        ], 'gst-summary', [
            'output_tax' => $out['tax'],
            'input_tax' => $in['tax'],
            'net_payable' => $net,
        ]);
    }

    /** GSTR-1: outward supplies, B2B/B2C + HSN + rate wise, CGST/SGST/IGST split. */
    public function gstr1(Request $request)
    {
        [$from, $to] = [$request->query('date_from'), $request->query('date_to')];
        $homeState = $this->homeState();

        $rows = SalesItem::query()
            ->join('sales_orders', 'sales_orders.id', '=', 'sales_items.sales_order_id')
            ->join('products', 'products.id', '=', 'sales_items.product_id')
            ->leftJoin('customers', 'customers.id', '=', 'sales_orders.customer_id')
            ->where('sales_orders.status', '!=', 'cancelled')
            ->when($from, fn ($q) => $q->whereDate('sales_orders.order_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('sales_orders.order_date', '<=', $to))
            ->selectRaw("
                CASE WHEN customers.gst_number IS NULL OR customers.gst_number = '' THEN 'B2C' ELSE 'B2B' END as supply_type,
                CASE WHEN customers.state IS NULL OR customers.state = '' OR LOWER(TRIM(customers.state)) = ? THEN 0 ELSE 1 END as is_interstate,
                COALESCE(products.hsn_code, 'N/A') as hsn_code,
                sales_items.tax_percent,
                SUM(sales_items.line_total - sales_items.tax_amount) as taxable_value,
                SUM(sales_items.tax_amount) as tax_amount
            ", [$homeState])
            ->groupBy('supply_type', 'is_interstate', 'hsn_code', 'sales_items.tax_percent')
            ->orderBy('supply_type')
            ->orderBy('hsn_code')
            ->get()
            ->map(function ($r) {
                $tax = round((float) $r->tax_amount, 2);
                $interstate = (int) $r->is_interstate === 1;

                return [
                    'supply_type' => $r->supply_type,
                    'hsn_code' => $r->hsn_code,
                    'tax_percent' => (float) $r->tax_percent,
                    'taxable_value' => round((float) $r->taxable_value, 2),
                    'cgst' => $interstate ? 0.0 : round($tax / 2, 2),
                    'sgst' => $interstate ? 0.0 : round($tax / 2, 2),
                    'igst' => $interstate ? $tax : 0.0,
                    'tax_amount' => $tax,
                ];
            })->all();

        return $this->exportResponse($request, $rows, [
            'supply_type' => 'Supply', 'hsn_code' => 'HSN Code', 'tax_percent' => 'Tax %',
            'taxable_value' => 'Taxable Value', 'cgst' => 'CGST', 'sgst' => 'SGST', 'igst' => 'IGST', 'tax_amount' => 'Total Tax',
        ], 'gstr1', [
            'total_taxable' => round(array_sum(array_column($rows, 'taxable_value')), 2),
            'total_tax' => round(array_sum(array_column($rows, 'tax_amount')), 2),
        ]);
    }

    /** @return array{taxable:float,tax:float} */
    private function salesTaxTotals(?string $from, ?string $to): array
    {
        $row = SalesItem::query()
            ->join('sales_orders', 'sales_orders.id', '=', 'sales_items.sales_order_id')
            ->where('sales_orders.status', '!=', 'cancelled')
            ->when($from, fn ($q) => $q->whereDate('sales_orders.order_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('sales_orders.order_date', '<=', $to))
            ->selectRaw('SUM(sales_items.line_total - sales_items.tax_amount) as taxable, SUM(sales_items.tax_amount) as tax')
            ->first();

        return ['taxable' => round((float) $row->taxable, 2), 'tax' => round((float) $row->tax, 2)];
    }

    /** @return array{taxable:float,tax:float} */
    private function purchaseTaxTotals(?string $from, ?string $to): array
    {
        $row = PurchaseInvoiceItem::query()
            ->join('purchase_invoices', 'purchase_invoices.id', '=', 'purchase_invoice_items.purchase_invoice_id')
            ->when($from, fn ($q) => $q->whereDate('purchase_invoices.invoice_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('purchase_invoices.invoice_date', '<=', $to))
            ->selectRaw('SUM(purchase_invoice_items.line_total - purchase_invoice_items.tax_amount) as taxable, SUM(purchase_invoice_items.tax_amount) as tax')
            ->first();

        return ['taxable' => round((float) $row->taxable, 2), 'tax' => round((float) $row->tax, 2)];
    }

    /** The company's home state (for the intra/inter-state split), lower-cased. */
    private function homeState(): string
    {
        $state = GstRegistration::query()->value('state') ?? Company::query()->value('state');

        return strtolower(trim((string) $state));
    }
}
