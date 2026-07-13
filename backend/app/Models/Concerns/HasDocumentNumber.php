<?php

namespace App\Models\Concerns;

use App\Models\Setting;

/**
 * Sequential document numbering with a configurable prefix (SRS §4.12
 * "invoice numbering"). Host models declare which settings key holds their
 * prefix override:
 *
 *   protected static string $numberSettingKey = 'po_number_prefix';
 */
trait HasDocumentNumber
{
    public static function nextNumber(): string
    {
        $last = static::query()->orderByDesc('id')->value('id') ?? 0;
        $prefix = Setting::numberPrefix(static::$numberSettingKey);

        return $prefix.'-'.str_pad((string) ($last + 1), 6, '0', STR_PAD_LEFT);
    }
}
