<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\GstinValidator;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates that an attribute is a structurally valid, check-digit-correct
 * GSTIN. Apply as a `nullable` rule — empty values pass so unregistered
 * parties (B2C consumers) are not forced to carry a GSTIN.
 */
class ValidGstin implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $result = GstinValidator::validate((string) $value);
        if (! $result['valid']) {
            $fail($result['errors'][0] ?? 'The :attribute is not a valid GSTIN.');
        }
    }
}
