<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\EyePrescription;
use App\Models\Invoice;
use App\Models\OpticalOrder;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\Warehouse;
use RuntimeException;

/**
 * The rules an optician works under.
 *
 * Optical powers are not free-form numbers. They step in quarter dioptres, a
 * cylinder without an axis is meaningless, and a lens can only be ground within
 * the range its blank allows. Every one of those is a mistake that costs a pair
 * of lenses, so they are checked here rather than trusted from the form.
 *
 * Billing follows the same route as every other module: an ordinary Sales Order
 * on the 'optical' channel plus an Invoice.
 */
class OpticalService
{
    /** Optical powers are dispensed in quarter-dioptre steps. */
    private const STEP = 0.25;

    /** Sanity bounds — beyond these it is a typo, not a prescription. */
    private const SPH_LIMIT = 30.0;
    private const CYL_LIMIT = 12.0;
    private const ADD_LIMIT = 4.0;

    public function __construct(private readonly BrokerCommissionService $brokerCommissions) {}

    /**
     * Validate one eye's powers.
     *
     * @param  array<string, mixed>  $eye  sph, cyl, axis, add
     * @return list<string> Human-readable problems; empty means it is dispensable.
     */
    public function validateEye(array $eye, string $label, bool $expectsAdd): array
    {
        $errors = [];

        $sph = $this->number($eye['sph'] ?? null);
        $cyl = $this->number($eye['cyl'] ?? null);
        $axis = $eye['axis'] ?? null;
        $add = $this->number($eye['add'] ?? null);

        foreach ([['sph', $sph, self::SPH_LIMIT], ['cyl', $cyl, self::CYL_LIMIT]] as [$name, $value, $limit]) {
            if ($value === null) {
                continue;
            }
            if (abs($value) > $limit) {
                $errors[] = "{$label} {$name} of {$value} is outside the dispensable range (±{$limit}).";
            } elseif (! $this->isQuarterStep($value)) {
                $errors[] = "{$label} {$name} must be in steps of 0.25 — {$value} cannot be ground.";
            }
        }

        // A cylinder describes astigmatism at an angle; without the angle the
        // lab cannot orient it, and 0–180 covers every orientation there is.
        if ($cyl !== null && $cyl != 0.0) {
            if ($axis === null || $axis === '') {
                $errors[] = "{$label} has a cylinder but no axis.";
            } elseif ((int) $axis < 0 || (int) $axis > 180) {
                $errors[] = "{$label} axis must be between 0 and 180 degrees.";
            }
        }

        if ($add !== null && $add != 0.0) {
            if (! $expectsAdd) {
                $errors[] = "{$label} carries a near addition, which only applies to a bifocal or progressive pair.";
            } elseif ($add < 0) {
                $errors[] = "{$label} near addition must be positive.";
            } elseif ($add > self::ADD_LIMIT) {
                $errors[] = "{$label} near addition of {$add} is outside the usual range (up to +".self::ADD_LIMIT.').';
            } elseif (! $this->isQuarterStep($add)) {
                $errors[] = "{$label} near addition must be in steps of 0.25.";
            }
        }

        return $errors;
    }

    /**
     * Validate a whole prescription (both eyes) against its vision type.
     *
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    public function validatePrescription(array $data): array
    {
        $expectsAdd = in_array($data['vision_type'] ?? 'distance', EyePrescription::ADD_VISION_TYPES, true);

        return array_merge(
            $this->validateEye([
                'sph' => $data['right_sph'] ?? null, 'cyl' => $data['right_cyl'] ?? null,
                'axis' => $data['right_axis'] ?? null, 'add' => $data['right_add'] ?? null,
            ], 'Right eye', $expectsAdd),
            $this->validateEye([
                'sph' => $data['left_sph'] ?? null, 'cyl' => $data['left_cyl'] ?? null,
                'axis' => $data['left_axis'] ?? null, 'add' => $data['left_add'] ?? null,
            ], 'Left eye', $expectsAdd),
        );
    }

    /**
     * Can this lens actually be made to these powers?
     *
     * A blank has a range; ordering outside it wastes a trip to the lab and a
     * week of the customer's time. Ranges left blank on the product mean "no
     * stated limit" and are not treated as zero.
     *
     * @return list<string>
     */
    public function checkLensFits(Product $lens, OpticalOrder|array $powers): array
    {
        $errors = [];
        $get = fn (string $key) => is_array($powers) ? ($powers[$key] ?? null) : $powers->{$key};

        foreach (['right', 'left'] as $eye) {
            $sph = $this->number($get("{$eye}_sph"));
            $cyl = $this->number($get("{$eye}_cyl"));
            $label = ucfirst($eye).' eye';

            if ($sph !== null && $lens->min_sph !== null && $sph < (float) $lens->min_sph) {
                $errors[] = "{$label} sphere {$sph} is below what {$lens->name} can be made in (min {$lens->min_sph}).";
            }
            if ($sph !== null && $lens->max_sph !== null && $sph > (float) $lens->max_sph) {
                $errors[] = "{$label} sphere {$sph} is above what {$lens->name} can be made in (max {$lens->max_sph}).";
            }
            if ($cyl !== null && $lens->max_cyl !== null && abs($cyl) > abs((float) $lens->max_cyl)) {
                $errors[] = "{$label} cylinder {$cyl} exceeds what {$lens->name} supports (max {$lens->max_cyl}).";
            }
        }

        // A single-vision lens cannot carry a near addition, whatever powers
        // were typed against it.
        if ($lens->lens_type === 'single_vision') {
            foreach (['right', 'left'] as $eye) {
                $add = $this->number($get("{$eye}_add"));
                if ($add !== null && $add != 0.0) {
                    $errors[] = "{$lens->name} is single vision and cannot carry a near addition.";
                    break;
                }
            }
        }

        return $errors;
    }

    /** Recompute an order's money from its parts and write it back. */
    public function reprice(OpticalOrder $order): OpticalOrder
    {
        $gross = round(
            (float) $order->frame_price + (float) $order->lens_price + (float) $order->fitting_charge,
            2,
        );

        $discount = match ($order->discount_type) {
            'percent' => round($gross * (float) $order->discount_value / 100, 2),
            'flat' => min($gross, (float) $order->discount_value),
            default => 0.0,
        };

        $taxable = round($gross - $discount, 2);
        $tax = round($taxable * (float) $order->tax_percent / 100, 2);
        $preRound = $taxable + $tax;
        $total = round($preRound, 0);

        $order->fill([
            'discount_amount' => $discount,
            'tax_amount' => $tax,
            'round_off' => round($total - $preRound, 2),
            'total' => $total,
        ])->save();

        return $order;
    }

    /**
     * Bill a delivered pair. Idempotent — an order that already has an invoice
     * returns it untouched, so a double-click at the counter cannot bill twice.
     */
    public function bill(OpticalOrder $order, ?int $userId = null): ?Invoice
    {
        if ($order->invoice_id) {
            return $order->invoice()->first();
        }

        if ((float) $order->total <= 0) {
            return null;
        }

        $order->loadMissing(['frame', 'lens']);

        $salesOrder = SalesOrder::create([
            'so_number' => SalesOrder::nextNumber(),
            'customer_id' => $order->customer_id,
            'warehouse_id' => $order->warehouse_id ?? $this->fallbackWarehouseId($order),
            'status' => 'delivered',
            'channel' => 'optical',
            'order_date' => now()->toDateString(),
            'subtotal' => round(
                (float) $order->frame_price + (float) $order->lens_price + (float) $order->fitting_charge,
                2,
            ),
            'item_discount_total' => 0,
            'discount_type' => $order->discount_type,
            'discount_value' => $order->discount_value,
            'discount_amount' => $order->discount_amount,
            'tax_amount' => $order->tax_amount,
            'round_off' => $order->round_off,
            'total' => $order->total,
            'notes' => "Optical order {$order->order_number}",
            'created_by' => $userId,
            'delivered_at' => now(),
        ]);

        foreach ([
            [$order->frame, (float) $order->frame_price],
            [$order->lens, (float) $order->lens_price],
        ] as [$product, $price]) {
            if (! $product || $price <= 0) {
                continue;
            }

            $salesOrder->items()->create([
                'product_id' => $product->id,
                'variant_id' => null,
                'quantity' => 1,
                'unit_price' => $price,
                'discount_type' => null,
                'discount_value' => 0,
                'discount_amount' => 0,
                'tax_percent' => $order->tax_percent,
                'tax_amount' => round($price * (float) $order->tax_percent / 100, 2),
                'line_total' => round($price * (1 + (float) $order->tax_percent / 100), 2),
            ]);
        }

        $invoice = Invoice::create([
            'invoice_number' => Invoice::nextNumber(),
            'sales_order_id' => $salesOrder->id,
            'customer_id' => $order->customer_id,
            'invoice_date' => now()->toDateString(),
            'total' => $order->total,
            'paid_amount' => 0,
            'status' => 'unpaid',
        ]);

        $this->brokerCommissions->accrueForInvoice($invoice);

        $order->forceFill([
            'sales_order_id' => $salesOrder->id,
            'invoice_id' => $invoice->id,
        ])->save();

        return $invoice;
    }

    /** Is a value on a quarter-dioptre step? Tolerant of float representation. */
    private function isQuarterStep(float $value): bool
    {
        return abs(fmod(round($value, 2) / self::STEP, 1.0)) < 0.0001;
    }

    private function number(mixed $value): ?float
    {
        return $value === null || $value === '' ? null : (float) $value;
    }

    private function fallbackWarehouseId(OpticalOrder $order): int
    {
        $id = Warehouse::query()->orderBy('id')->value('id');

        if (! $id) {
            throw new RuntimeException("Create a warehouse before billing optical order {$order->order_number}.");
        }

        return (int) $id;
    }
}
