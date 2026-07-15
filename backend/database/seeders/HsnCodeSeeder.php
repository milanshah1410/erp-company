<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\HsnCode;
use Illuminate\Database\Seeder;

/**
 * Baseline HSN/SAC master for a textile & apparel tenant. Runs inside a
 * TenantContext (called from TenantDatabaseSeeder) so rows are tenant-stamped.
 * Rates are the common GST slabs for these chapters and can be edited per tenant.
 */
class HsnCodeSeeder extends Seeder
{
    public function run(): void
    {
        $codes = [
            // Fabrics (Chapters 52/54/55) — 5% GST.
            ['code' => '5208', 'description' => 'Woven cotton fabrics', 'gst_rate' => 5, 'uqc' => 'MTR'],
            ['code' => '5407', 'description' => 'Woven fabrics of synthetic filament yarn', 'gst_rate' => 5, 'uqc' => 'MTR'],
            ['code' => '5515', 'description' => 'Woven fabrics of synthetic staple fibres', 'gst_rate' => 5, 'uqc' => 'MTR'],
            ['code' => '6006', 'description' => 'Knitted or crocheted fabrics', 'gst_rate' => 5, 'uqc' => 'MTR'],
            // Yarn / thread.
            ['code' => '5205', 'description' => 'Cotton yarn (not sewing thread)', 'gst_rate' => 5, 'uqc' => 'KGS'],
            ['code' => '5401', 'description' => 'Sewing thread of man-made filaments', 'gst_rate' => 12, 'uqc' => 'KGS'],
            // Apparel (Chapters 61/62) — 5% up to ₹1000, else 12%; seed the 5% base.
            ['code' => '6109', 'description' => 'T-shirts, singlets and vests, knitted', 'gst_rate' => 5, 'uqc' => 'PCS'],
            ['code' => '6203', 'description' => "Men's suits, jackets, trousers", 'gst_rate' => 5, 'uqc' => 'PCS'],
            ['code' => '6204', 'description' => "Women's suits, dresses, skirts", 'gst_rate' => 5, 'uqc' => 'PCS'],
            ['code' => '6205', 'description' => "Men's shirts", 'gst_rate' => 5, 'uqc' => 'PCS'],
            ['code' => '6206', 'description' => "Women's blouses and shirts", 'gst_rate' => 5, 'uqc' => 'PCS'],
            // Made-ups / accessories.
            ['code' => '6302', 'description' => 'Bed linen, table linen, toilet & kitchen linen', 'gst_rate' => 5, 'uqc' => 'PCS'],
            ['code' => '6217', 'description' => 'Made-up clothing accessories', 'gst_rate' => 12, 'uqc' => 'PCS'],
            ['code' => '9607', 'description' => 'Slide fasteners (zippers) and parts', 'gst_rate' => 12, 'uqc' => 'PCS'],
            ['code' => '9606', 'description' => 'Buttons, press-fasteners and parts', 'gst_rate' => 18, 'uqc' => 'PCS'],
            // Common SAC (services).
            ['code' => '998821', 'description' => 'Textile manufacturing services (job work)', 'gst_rate' => 5, 'type' => 'service'],
            ['code' => '998822', 'description' => 'Wearing apparel manufacturing services', 'gst_rate' => 5, 'type' => 'service'],
            ['code' => '996511', 'description' => 'Road transport services of goods', 'gst_rate' => 5, 'type' => 'service'],
        ];

        foreach ($codes as $c) {
            HsnCode::updateOrCreate(
                ['code' => $c['code']],
                [
                    'type' => $c['type'] ?? 'goods',
                    'description' => $c['description'],
                    'gst_rate' => $c['gst_rate'],
                    'cess_rate' => 0,
                    'uqc' => $c['uqc'] ?? null,
                    'is_active' => true,
                ],
            );
        }
    }
}
