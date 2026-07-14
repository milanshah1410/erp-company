<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;

/**
 * Key/value company configuration (currently single-company, global).
 */
class Setting extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    public const COSTING_FIFO = 'fifo';
    public const COSTING_LIFO = 'lifo';
    public const COSTING_WEIGHTED_AVERAGE = 'weighted_average';

    public const COSTING_METHODS = [
        self::COSTING_FIFO,
        self::COSTING_LIFO,
        self::COSTING_WEIGHTED_AVERAGE,
    ];

    /** Configurable prefixes for each numbered document type — SRS §4.12 "invoice numbering". */
    public const NUMBER_PREFIX_DEFAULTS = [
        'po_number_prefix' => 'PO',
        'grn_number_prefix' => 'GRN',
        'invoice_number_prefix' => 'INV',
        'so_number_prefix' => 'SO',
        'quote_number_prefix' => 'QTE',
        'transfer_number_prefix' => 'TRF',
        'return_number_prefix' => 'RTN',
        'credit_note_number_prefix' => 'CN',
        'adjustment_number_prefix' => 'ADJ',
        'cycle_count_number_prefix' => 'CC',
        'gate_entry_number_prefix' => 'GTE',
        'broker_settlement_number_prefix' => 'BST',
        'tailor_work_number_prefix' => 'TW',
        'tailor_payment_number_prefix' => 'TPY',
        'mo_number_prefix' => 'MO',
    ];

    public const OTHER_DEFAULTS = [
        'costing_method' => self::COSTING_WEIGHTED_AVERAGE,
        'purchase_approval_threshold' => '0',
        'financial_year_start_month' => '4',
        'currency_code' => 'INR',
        'currency_symbol' => '₹',
        'default_tax_percent' => '0',
    ];

    protected $fillable = ['key', 'value'];

    public static function get(string $key, ?string $default = null): ?string
    {
        return static::query()->where('key', $key)->value('value') ?? $default;
    }

    public static function set(string $key, ?string $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }

    /** Every known setting key with its stored value, or its default when unset. */
    public static function allWithDefaults(): array
    {
        $defaults = array_merge(self::NUMBER_PREFIX_DEFAULTS, self::OTHER_DEFAULTS);
        $stored = static::query()->pluck('value', 'key')->toArray();

        return array_merge($defaults, array_filter($stored, fn ($v) => $v !== null));
    }

    /** The company's active inventory costing method. */
    public static function costingMethod(): string
    {
        return static::get('costing_method', self::COSTING_WEIGHTED_AVERAGE);
    }

    public static function financialYearStartMonth(): int
    {
        return (int) static::get('financial_year_start_month', self::OTHER_DEFAULTS['financial_year_start_month']);
    }

    public static function defaultTaxPercent(): float
    {
        return (float) static::get('default_tax_percent', self::OTHER_DEFAULTS['default_tax_percent']);
    }

    public static function currencyCode(): string
    {
        return static::get('currency_code', self::OTHER_DEFAULTS['currency_code']);
    }

    public static function currencySymbol(): string
    {
        return static::get('currency_symbol', self::OTHER_DEFAULTS['currency_symbol']);
    }

    /** The configured prefix for a document number setting key, e.g. "po_number_prefix" -> "PO". */
    public static function numberPrefix(string $settingKey): string
    {
        return static::get($settingKey, self::NUMBER_PREFIX_DEFAULTS[$settingKey] ?? '');
    }
}
