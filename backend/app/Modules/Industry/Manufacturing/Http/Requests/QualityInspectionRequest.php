<?php

declare(strict_types=1);

namespace App\Modules\Industry\Manufacturing\Http\Requests;

use App\Modules\Industry\Manufacturing\Models\QualityInspection;
use App\Modules\Industry\Manufacturing\Models\QualityInspectionDefect;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for an inspection.
 *
 * Two rules carry the weight, and both exist because a QC record that does not
 * add up is worse than no record — it is the document someone quotes back at a
 * customer:
 *
 *  1. accepted + rejected + rework may not exceed the lot offered. A lot of 100
 *     that produced 120 judged pieces means something was miscounted, and it is
 *     cheaper to catch that at the keyboard than in a dispute.
 *
 *  2. the defect lines may not account for more pieces than were rejected plus
 *     reworked. Defects explain the marked-down pieces; they cannot explain more
 *     pieces than were marked down.
 */
class QualityInspectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'stage' => ['required', Rule::in(array_keys(QualityInspection::STAGES))],
            'inspected_on' => ['required', 'date'],
            'manufacturing_order_id' => ['nullable', 'exists:manufacturing_orders,id'],
            'goods_receipt_id' => ['nullable', 'exists:goods_receipts,id'],
            'product_id' => ['required', 'exists:products,id'],
            'variant_id' => ['nullable', 'exists:product_variants,id'],
            'batch_id' => ['nullable', 'exists:batches,id'],

            'lot_quantity' => ['required', 'numeric', 'min:0'],
            'sample_size' => ['nullable', 'numeric', 'min:0'],
            'accepted_quantity' => ['nullable', 'numeric', 'min:0'],
            'rejected_quantity' => ['nullable', 'numeric', 'min:0'],
            'rework_quantity' => ['nullable', 'numeric', 'min:0'],

            'result' => ['nullable', Rule::in(QualityInspection::RESULTS)],
            'inspector_id' => ['nullable', 'exists:employees,id'],
            'remarks' => ['nullable', 'string', 'max:2000'],

            'defects' => ['nullable', 'array'],
            'defects.*.defect' => ['required', 'string', 'max:120'],
            'defects.*.quantity' => ['required', 'numeric', 'min:0'],
            'defects.*.severity' => ['nullable', Rule::in(QualityInspectionDefect::SEVERITIES)],
            'defects.*.remarks' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $lot = (float) $this->input('lot_quantity', 0);
            $accepted = (float) $this->input('accepted_quantity', 0);
            $rejected = (float) $this->input('rejected_quantity', 0);
            $rework = (float) $this->input('rework_quantity', 0);

            // Rounded before comparing: three decimal columns holding kilos or
            // metres would otherwise fail on floating-point dust.
            if ($lot > 0 && round($accepted + $rejected + $rework, 3) > round($lot, 3)) {
                $v->errors()->add(
                    'accepted_quantity',
                    'Accepted, rejected and rework together cannot exceed the lot quantity.',
                );
            }

            $defectQty = collect($this->input('defects', []))
                ->sum(fn ($d) => (float) ($d['quantity'] ?? 0));

            if ($defectQty > 0 && round($defectQty, 3) > round($rejected + $rework, 3)) {
                $v->errors()->add(
                    'defects',
                    'Defect quantities add up to more than the rejected and rework quantity.',
                );
            }

            $sample = (float) $this->input('sample_size', 0);
            if ($lot > 0 && $sample > $lot) {
                $v->errors()->add('sample_size', 'The sample cannot be larger than the lot.');
            }
        });
    }
}
