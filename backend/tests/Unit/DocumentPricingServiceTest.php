<?php

namespace Tests\Unit;

use App\Services\DocumentPricingService;
use PHPUnit\Framework\TestCase;

class DocumentPricingServiceTest extends TestCase
{
    private DocumentPricingService $pricing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pricing = new DocumentPricingService();
    }

    public function test_no_discounts_matches_plain_qty_price_plus_tax(): void
    {
        $result = $this->pricing->price([
            ['product_id' => 1, 'quantity' => 2, 'unit_price' => 100, 'tax_percent' => 18],
        ]);

        $h = $result['header'];
        $this->assertSame(200.0, $h['subtotal']);
        $this->assertSame(0.0, $h['item_discount_total']);
        $this->assertSame(0.0, $h['discount_amount']);
        $this->assertNull($h['discount_type']);
        $this->assertSame(36.0, $h['tax_amount']);
        $this->assertSame(0.0, $h['round_off']);
        $this->assertSame(236.0, $h['total']);
    }

    public function test_worked_example_from_plan(): void
    {
        // One line of 10000, ₹500 flat line discount -> net 9500,
        // then 12.5% overall -> taxable 8312.50, 6% GST -> 498.75,
        // pre-round 8811.25 -> grand total 8811.00, round_off -0.25.
        $result = $this->pricing->price([
            [
                'product_id' => 1, 'quantity' => 1, 'unit_price' => 10000,
                'tax_percent' => 6, 'discount_type' => 'flat', 'discount_value' => 500,
            ],
        ], 'percent', 12.5);

        $h = $result['header'];
        $this->assertSame(10000.0, $h['subtotal']);
        $this->assertSame(500.0, $h['item_discount_total']);
        $this->assertSame(1187.5, $h['discount_amount']);
        $this->assertSame(8312.5, $h['taxable']);
        $this->assertSame(498.75, $h['tax_amount']);
        $this->assertSame(-0.25, $h['round_off']);
        $this->assertSame(8811.0, $h['total']);
    }

    public function test_percentage_line_discount(): void
    {
        // 10 x 100 = 1000, 10% line discount -> 900 net, 18% tax -> 162.
        $result = $this->pricing->price([
            ['product_id' => 1, 'quantity' => 10, 'unit_price' => 100, 'tax_percent' => 18,
                'discount_type' => 'percent', 'discount_value' => 10],
        ]);

        $line = $result['lines'][0];
        $this->assertSame(100.0, $line['discount_amount']);
        $this->assertSame(162.0, $line['tax_amount']);
        $this->assertSame(1062.0, $line['line_total']);

        $h = $result['header'];
        $this->assertSame(100.0, $h['item_discount_total']);
        $this->assertSame(900.0, $h['taxable']);
        $this->assertSame(1062.0, $h['total']);
    }

    public function test_overall_discount_distributes_across_multi_rate_lines(): void
    {
        // Two lines: A 1000 @18%, B 1000 @5%. Overall flat 200 off (10% of 2000).
        // Each line's net share = 900. Tax: 900*0.18=162, 900*0.05=45 -> 207.
        $result = $this->pricing->price([
            ['product_id' => 1, 'quantity' => 1, 'unit_price' => 1000, 'tax_percent' => 18],
            ['product_id' => 2, 'quantity' => 1, 'unit_price' => 1000, 'tax_percent' => 5],
        ], 'flat', 200);

        $h = $result['header'];
        $this->assertSame(2000.0, $h['subtotal']);
        $this->assertSame(200.0, $h['discount_amount']);
        $this->assertSame(1800.0, $h['taxable']);
        $this->assertSame(207.0, $h['tax_amount']);

        // Distributed shares must sum exactly to the header discount.
        $sumShares = (1000 - $result['lines'][0]['line_total'] + $result['lines'][0]['tax_amount'])
            + (1000 - $result['lines'][1]['line_total'] + $result['lines'][1]['tax_amount']);
        $this->assertEqualsWithDelta(200.0, round($sumShares, 2), 0.001);

        $this->assertSame(162.0, $result['lines'][0]['tax_amount']);
        $this->assertSame(45.0, $result['lines'][1]['tax_amount']);
        $this->assertSame(2007.0, $h['total']);
    }

    public function test_stacked_line_and_overall_discount(): void
    {
        // 5 x 200 = 1000, 20% line -> 800 net, then 10% overall -> 720 taxable,
        // 12% tax -> 86.40, pre-round 806.40 -> total 806, round_off -0.40.
        $result = $this->pricing->price([
            ['product_id' => 1, 'quantity' => 5, 'unit_price' => 200, 'tax_percent' => 12,
                'discount_type' => 'percent', 'discount_value' => 20],
        ], 'percent', 10);

        $h = $result['header'];
        $this->assertSame(1000.0, $h['subtotal']);
        $this->assertSame(200.0, $h['item_discount_total']);
        $this->assertSame(80.0, $h['discount_amount']);
        $this->assertSame(720.0, $h['taxable']);
        $this->assertSame(86.4, $h['tax_amount']);
        $this->assertSame(-0.4, $h['round_off']);
        $this->assertSame(806.0, $h['total']);
    }

    public function test_flat_discount_capped_at_base(): void
    {
        // Flat 5000 off a 1000 line cannot go negative.
        $result = $this->pricing->price([
            ['product_id' => 1, 'quantity' => 1, 'unit_price' => 1000, 'tax_percent' => 0,
                'discount_type' => 'flat', 'discount_value' => 5000],
        ]);

        $h = $result['header'];
        $this->assertSame(1000.0, $h['item_discount_total']);
        $this->assertSame(0.0, $h['taxable']);
        $this->assertSame(0.0, $h['total']);
    }
}
