<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Vocabulary for the Import & Export Logistics module (SRS §24).
 *
 * The three lists below are stored as plain strings on their tables rather than
 * enums, because the required document set, the cost heads a forwarder bills and
 * the milestones a lane passes through all vary by country, commodity and
 * carrier. This class is the catalogue the UI offers and the API validates
 * against, and the one place to extend when a tenant needs another head.
 *
 * Exposed to the frontend by ShipmentController::catalogue()
 * (GET /api/import-export/catalogue) so the pickers never hard-code the lists.
 */
final class TradeCatalogue
{
    /**
     * Trade documents. `import` / `export` flag which leg a document belongs to
     * (both = it appears on either), and `mandatory` seeds the default document
     * checklist for a new shipment.
     *
     * @var array<string, array{label: string, legs: list<string>, mandatory: bool}>
     */
    public const DOCUMENTS = [
        'commercial_invoice' => ['label' => 'Commercial Invoice', 'legs' => ['import', 'export'], 'mandatory' => true],
        'packing_list' => ['label' => 'Packing List', 'legs' => ['import', 'export'], 'mandatory' => true],
        'bill_of_lading' => ['label' => 'Bill of Lading / AWB', 'legs' => ['import', 'export'], 'mandatory' => true],
        'certificate_of_origin' => ['label' => 'Certificate of Origin', 'legs' => ['import', 'export'], 'mandatory' => false],
        'insurance_certificate' => ['label' => 'Insurance Certificate', 'legs' => ['import', 'export'], 'mandatory' => false],
        'purchase_order' => ['label' => 'Purchase Order', 'legs' => ['import'], 'mandatory' => false],
        'proforma_invoice' => ['label' => 'Proforma Invoice', 'legs' => ['export'], 'mandatory' => false],
        'letter_of_credit' => ['label' => 'Letter of Credit', 'legs' => ['import', 'export'], 'mandatory' => false],
        'bill_of_entry' => ['label' => 'Bill of Entry', 'legs' => ['import'], 'mandatory' => true],
        'shipping_bill' => ['label' => 'Shipping Bill', 'legs' => ['export'], 'mandatory' => true],
        'import_license' => ['label' => 'Import Licence', 'legs' => ['import'], 'mandatory' => false],
        'phytosanitary' => ['label' => 'Phytosanitary / Fumigation', 'legs' => ['import', 'export'], 'mandatory' => false],
        'inspection_certificate' => ['label' => 'Inspection Certificate', 'legs' => ['import', 'export'], 'mandatory' => false],
        'delivery_order' => ['label' => 'Delivery Order', 'legs' => ['import'], 'mandatory' => false],
        'brc' => ['label' => 'Bank Realisation Certificate', 'legs' => ['export'], 'mandatory' => false],
        'gst_lut' => ['label' => 'GST LUT / Bond', 'legs' => ['export'], 'mandatory' => false],
        'other' => ['label' => 'Other', 'legs' => ['import', 'export'], 'mandatory' => false],
    ];

    /**
     * Logistics cost heads.
     *
     * @var array<string, string>
     */
    public const EXPENSE_TYPES = [
        'ocean_freight' => 'Ocean Freight',
        'air_freight' => 'Air Freight',
        'local_charges' => 'Local Charges',
        'thc' => 'Terminal Handling (THC)',
        'port_charges' => 'Port / Airport Charges',
        'cfs_charges' => 'CFS Charges',
        'cha_charges' => 'CHA / Clearing Charges',
        'customs_duty' => 'Customs Duty',
        'inland_transport' => 'Inland Transport',
        'warehousing' => 'Warehousing',
        'detention' => 'Detention',
        'demurrage' => 'Demurrage',
        'insurance' => 'Marine Insurance',
        'inspection' => 'Inspection / Testing',
        'documentation' => 'Documentation',
        'bank_charges' => 'Bank Charges',
        'other' => 'Other',
    ];

    /**
     * Who can bill a logistics cost. Each key is a master the expense's
     * vendor_id points into — see ShipmentExpense::VENDOR_MODELS, which maps them
     * to their models; 'other' means a free-text vendor name.
     *
     * @var array<string, string>
     */
    public const VENDOR_TYPES = [
        'freight_forwarder' => 'Freight Forwarder',
        'cha_agent' => 'CHA Agent',
        'transporter' => 'Transporter',
        'shipping_line' => 'Shipping Line',
        'airline' => 'Airline',
        'supplier' => 'Supplier',
        'other' => 'Other',
    ];

    /**
     * Timeline milestones, in the order a shipment normally passes them.
     *
     * @var array<string, string>
     */
    public const MILESTONES = [
        'booking_confirmed' => 'Booking Confirmed',
        'cargo_ready' => 'Cargo Ready',
        'stuffing_done' => 'Stuffing / Loading Done',
        'gated_in' => 'Gated In at Port',
        'customs_filed' => 'Customs Filed',
        'customs_cleared' => 'Customs Cleared',
        'departed' => 'Vessel / Flight Departed',
        'in_transit' => 'In Transit',
        'transhipment' => 'Transhipment',
        'arrived' => 'Arrived at Destination',
        'discharged' => 'Discharged',
        'duty_paid' => 'Duty Paid',
        'out_of_charge' => 'Out of Charge / Let Export',
        'gated_out' => 'Gated Out',
        // Handover points into the rest of the ERP: an import lands in a GRN,
        // an export leaves on a dispatch plan.
        'warehouse_receipt' => 'Warehouse Receipt Posted',
        'dispatched' => 'Dispatched',
        'delivered' => 'Delivered',
        'empty_returned' => 'Empty Container Returned',
        'document_received' => 'Document Received',
        'payment_received' => 'Payment Received',
        'payment_made' => 'Payment Made',
        'status_changed' => 'Status Changed',
        'delay_reported' => 'Delay Reported',
        'other' => 'Other',
    ];

    /** Document keys valid for a leg ('import' | 'export'). @return list<string> */
    public static function documentKeysFor(string $leg): array
    {
        return array_keys(array_filter(
            self::DOCUMENTS,
            static fn (array $doc) => in_array($leg, $doc['legs'], true),
        ));
    }

    /**
     * The default checklist a new shipment starts with: every mandatory document
     * for its leg, created in 'pending' state so it shows up on the Pending
     * Documents board immediately.
     *
     * @return list<string>
     */
    public static function mandatoryDocumentsFor(string $leg): array
    {
        return array_keys(array_filter(
            self::DOCUMENTS,
            static fn (array $doc) => $doc['mandatory'] && in_array($leg, $doc['legs'], true),
        ));
    }

    /** Human label for a document key. */
    public static function documentLabel(string $key): string
    {
        return self::DOCUMENTS[$key]['label'] ?? str($key)->headline()->value();
    }

    /** Human label for an expense head. */
    public static function expenseLabel(string $key): string
    {
        return self::EXPENSE_TYPES[$key] ?? str($key)->headline()->value();
    }

    /** Human label for a milestone. */
    public static function milestoneLabel(string $key): string
    {
        return self::MILESTONES[$key] ?? str($key)->headline()->value();
    }

    /**
     * The catalogue as option lists, ready for the frontend's pickers.
     *
     * @return array<string, mixed>
     */
    public static function options(): array
    {
        $documents = [];
        foreach (self::DOCUMENTS as $key => $doc) {
            $documents[] = ['value' => $key, 'label' => $doc['label'], 'legs' => $doc['legs'], 'mandatory' => $doc['mandatory']];
        }

        return [
            'documents' => $documents,
            'expense_types' => self::asOptions(self::EXPENSE_TYPES),
            'milestones' => self::asOptions(self::MILESTONES),
            'vendor_types' => self::asOptions(self::VENDOR_TYPES),
        ];
    }

    /**
     * @param  array<string, string>  $map
     * @return list<array{value: string, label: string}>
     */
    private static function asOptions(array $map): array
    {
        $out = [];
        foreach ($map as $value => $label) {
            $out[] = ['value' => $value, 'label' => $label];
        }

        return $out;
    }
}
