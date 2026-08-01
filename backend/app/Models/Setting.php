<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\InvalidatesTenantCache;
use App\Models\Concerns\LogsAuditActivity;
use App\Support\TenantCache;
use Illuminate\Database\Eloquent\Model;

/**
 * Key/value company configuration (currently single-company, global).
 */
class Setting extends Model
{
    use BelongsToTenant, InvalidatesTenantCache, LogsAuditActivity;

    /** Cache the settings map for 5 minutes; writes bust it via the version bump. */
    private const CACHE_TTL = 300;

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
        'indent_number_prefix' => 'PI',
        'rfq_number_prefix' => 'RFQ',
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
        'lead_number_prefix' => 'LD',
        'tailor_work_number_prefix' => 'TW',
        'tailor_payment_number_prefix' => 'TPY',
        'tailor_alteration_number_prefix' => 'ALT',
        'tailor_bonus_number_prefix' => 'BON',
        'embroidery_work_number_prefix' => 'EMB',
        'embroidery_payment_number_prefix' => 'EPY',
        'bom_number_prefix' => 'BOM',
        'mo_number_prefix' => 'MO',
        'jobwork_number_prefix' => 'JW',
        'roll_number_prefix' => 'ROLL',
        'fabric_inspection_number_prefix' => 'FINS',
        'shrinkage_number_prefix' => 'SHR',
        'cutting_number_prefix' => 'CUT',
        'expense_number_prefix' => 'EXP',
        'employee_claim_number_prefix' => 'ECL',
        'vehicle_expense_number_prefix' => 'VEX',
        'fuel_expense_number_prefix' => 'FUL',
        'electricity_bill_number_prefix' => 'ELC',
        'factory_maintenance_number_prefix' => 'FMN',
        'asset_maintenance_number_prefix' => 'AMN',
        'service_job_number_prefix' => 'SVC',
        'service_contract_number_prefix' => 'AMC',
        // Automobile. A job card numbers as an ordinary service job (SVC) — it
        // is one — so only the two documents the plugin adds are here.
        'vehicle_booking_number_prefix' => 'BKG',
        'warranty_number_prefix' => 'WTY',
        'project_number_prefix' => 'PRJ',
        'prescription_number_prefix' => 'RX',
        // Healthcare. A lab order number is read out to a patient collecting a
        // report, and a ward indent number is what a nurse quotes when chasing
        // the store — both need a short series of their own ('PI' is already the
        // purchase indent's).
        'lab_order_number_prefix' => 'LAB',
        'hospital_indent_number_prefix' => 'WIN',
        'old_gold_receipt_number_prefix' => 'OG',
        'eye_prescription_number_prefix' => 'EYE',
        'optical_order_number_prefix' => 'OPT',
        'restaurant_order_number_prefix' => 'RO',
        'kot_number_prefix' => 'KOT',
        // Manufacturing shop floor. Both are documents a factory quotes back —
        // an inspection number ends up on a customer's rejection claim, and a
        // scrap note is what an auditor asks to see behind a write-off.
        'qc_number_prefix' => 'QC',
        'scrap_number_prefix' => 'SCR',
        // Food & Beverage trades. A cake order and a milk round are documents a
        // customer is quoted by number over a counter or a phone, so each needs
        // its own short, sayable series.
        'cake_order_number_prefix' => 'CAKE',
        'milk_collection_number_prefix' => 'MC',
        'milk_subscription_number_prefix' => 'MS',
        'dispatch_number_prefix' => 'DSP',
        'transport_charge_number_prefix' => 'TRC',
        // Import/export shipments number per leg, so each gets its own prefix
        // ('EXP' is already taken by the Expenses module).
        'import_shipment_number_prefix' => 'IMP',
        'export_shipment_number_prefix' => 'EXPO',
        'payment_number_prefix' => 'PMT',
        'receipt_voucher_number_prefix' => 'RV',
        'payment_voucher_number_prefix' => 'PV',
        'contra_voucher_number_prefix' => 'CV',
        'journal_voucher_number_prefix' => 'JV',
    ];

    public const OTHER_DEFAULTS = [
        'costing_method' => self::COSTING_WEIGHTED_AVERAGE,
        'purchase_approval_threshold' => '0',
        'financial_year_start_month' => '4',
        'currency_code' => 'INR',
        'currency_symbol' => '₹',
        'default_tax_percent' => '0',
        'whatsapp_country_code' => '91',

        // SMS gateway (SRS §18). Driver `log` records messages without a gateway;
        // `msg91` sends for real using the auth key + sender id below.
        'sms_driver' => 'log',
        'sms_provider' => 'msg91',
        'sms_sender_id' => 'ERPTXT',
        'sms_route' => '4',
        'sms_country_code' => '91',
        'sms_auth_key' => '',
        'sms_otp_length' => '6',
        'sms_otp_ttl' => '300',
        // Message templates — {placeholders} are filled in at send time.
        'sms_tpl_otp' => 'Your OTP is {otp}. It is valid for {minutes} minutes. Do not share it with anyone.',
        'sms_tpl_invoice' => 'Dear {name}, invoice {number} for {symbol}{amount} has been generated. Thank you for your business.',
        'sms_tpl_dispatch' => 'Dear {name}, your order (Dispatch {number}) has been dispatched on {date} and is on its way. - {business}',
        'sms_tpl_payment_reminder' => 'Dear {name}, an amount of {symbol}{amount} is outstanding on your account. Kindly arrange payment at the earliest. - {business}',
        'sms_tpl_festival' => 'Dear {name}, {business} wishes you and your family a very Happy {festival}!',

        // Email / SMTP gateway (SRS §19). Driver `log` writes mail to the Laravel
        // log without a mail server; `smtp` sends for real over the SMTP account
        // configured below. Mirrors the SMS module's log-first philosophy.
        'mail_driver' => 'log',
        'mail_host' => '',
        'mail_port' => '587',
        'mail_username' => '',
        'mail_password' => '',
        'mail_encryption' => 'tls',        // tls / ssl / none
        'mail_from_address' => '',
        'mail_from_name' => '',

        // AI Features (SRS §22). All engines run locally; the narration provider
        // seam mirrors the SMS/GST modules. `local` needs no key; `openai` /
        // `anthropic` narrate via a hosted model once an API key is set.
        'ai_provider' => 'local',          // local / openai / anthropic
        'ai_api_key' => '',
        'ai_model' => '',                  // blank = provider default (config/ai.php)
        'ai_lead_time_days' => '7',        // supplier lead time for purchase suggestions

        // White label (Volume 11 §"White Label"). Set by the SaaS owner per
        // tenant from /platform/tenants → White Label, read back by the SPA
        // through the public GET /api/branding (App\Support\Branding). Defaults
        // are the platform's own brand, so a tenant that was never white
        // labelled looks exactly as it does today.
        // The logo is deliberately NOT a key of its own: it is
        // `company_logo_url` — the same image the invoices already print, so a
        // workspace can never end up with two competing logos.
        'brand_app_name' => '',            // blank = the platform name
        'brand_tagline' => '',             // one line under the logo on sign-in
        'brand_primary_color' => '',       // blank = --safety-amber (#e8a33d)
        'brand_accent_color' => '',        // blank = --ink-blue (#2c4a5e)
        'brand_dark_default' => '0',       // first visit opens in dark mode
        'brand_powered_by' => '1',         // show the "powered by" credit line

        // Loyalty programme (SRS §21). Points are earned per unit of spend and
        // redeemed as a flat discount, which is how Indian retail counters run it
        // — "₹1 off per point" reads at the till in a way percentages do not.
        // Off by default: a workspace opts in before any counter starts awarding.
        'loyalty_enabled' => '0',
        'loyalty_points_per_amount' => '1',   // points earned per `loyalty_earn_amount_unit`
        'loyalty_earn_amount_unit' => '100',  // …of net sale value
        'loyalty_redeem_value' => '1',        // rupees knocked off per point redeemed
        'loyalty_min_redeem_points' => '100', // smallest redemption allowed
        'loyalty_min_bill_amount' => '0',     // bills below this earn nothing
        'loyalty_max_redeem_percent' => '50', // cap: points may pay at most this % of a bill
    ];

    /** Loyalty keys, so the programme's config can be read as one block. */
    public const LOYALTY_KEYS = [
        'loyalty_enabled', 'loyalty_points_per_amount', 'loyalty_earn_amount_unit',
        'loyalty_redeem_value', 'loyalty_min_redeem_points', 'loyalty_min_bill_amount',
        'loyalty_max_redeem_percent',
    ];

    /** SMS keys that hold a template body (validated as free text, not codes). */
    public const SMS_TEMPLATE_KEYS = [
        'sms_tpl_otp', 'sms_tpl_invoice', 'sms_tpl_dispatch',
        'sms_tpl_payment_reminder', 'sms_tpl_festival',
    ];

    protected $fillable = ['key', 'value'];

    /**
     * The tenant's stored key => value map, cached (§17 Performance — Caching).
     * Every read path below goes through here, so the settings table is queried
     * at most once per tenant per 5-minute window; a create/update/delete on any
     * Setting row bumps the cache version (InvalidatesTenantCache) and the next
     * read rebuilds it. Settings are read on nearly every request, so this
     * removes a query from the hot path without risking staleness.
     */
    protected static function stored(): array
    {
        return TenantCache::rememberVersioned(
            'settings',
            'map',
            self::CACHE_TTL,
            fn () => static::query()->pluck('value', 'key')->toArray(),
        );
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        return static::stored()[$key] ?? $default;
    }

    public static function set(string $key, ?string $value): void
    {
        // updateOrCreate fires saved(), which bumps the settings cache version.
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }

    /** Every known setting key with its stored value, or its default when unset. */
    public static function allWithDefaults(): array
    {
        $defaults = array_merge(self::NUMBER_PREFIX_DEFAULTS, self::OTHER_DEFAULTS);

        return array_merge($defaults, array_filter(static::stored(), fn ($v) => $v !== null));
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

    /**
     * Keys holding the tenant's white-label appearance. `company_logo_url` is
     * part of the set: the brand logo and the invoice logo are one image.
     */
    public const BRAND_KEYS = [
        'company_logo_url', 'brand_app_name', 'brand_tagline',
        'brand_primary_color', 'brand_accent_color',
        'brand_dark_default', 'brand_powered_by',
    ];

    /** Keys holding outgoing-mail (SMTP) configuration. */
    public const MAIL_KEYS = [
        'mail_driver', 'mail_host', 'mail_port', 'mail_username', 'mail_password',
        'mail_encryption', 'mail_from_address', 'mail_from_name',
    ];

    /** The tenant's mail settings as a flat map, defaults filled in for anything unset. */
    public static function mailConfig(): array
    {
        $stored = array_intersect_key(static::stored(), array_flip(self::MAIL_KEYS));

        return array_merge(
            array_intersect_key(self::OTHER_DEFAULTS, array_flip(self::MAIL_KEYS)),
            array_filter($stored, fn ($v) => $v !== null),
        );
    }

    /** True when the tenant has entered enough SMTP config to send real mail. */
    public static function mailConfigured(): bool
    {
        $c = static::mailConfig();

        return ($c['mail_driver'] ?? 'log') === 'smtp'
            && ! empty($c['mail_host'])
            && ! empty($c['mail_from_address']);
    }
}
