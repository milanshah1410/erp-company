<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SettingController extends Controller
{
    /** All settings as a flat key => value map, defaults filled in for anything unset. */
    public function index()
    {
        return response()->json([
            'data' => Setting::allWithDefaults(),
        ]);
    }

    /** Update one or more known settings. Unknown keys are rejected. */
    public function update(Request $request)
    {
        $rules = [
            'costing_method' => ['sometimes', 'required', Rule::in(Setting::COSTING_METHODS)],
            'purchase_approval_threshold' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'sales_payment_term_days' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'financial_year_start_month' => ['sometimes', 'required', 'integer', 'min:1', 'max:12'],
            'currency_code' => ['sometimes', 'required', 'string', 'max:10'],
            'currency_symbol' => ['sometimes', 'required', 'string', 'max:5'],
            'default_tax_percent' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],

            // SMS gateway (SRS §18)
            'sms_driver' => ['sometimes', 'required', Rule::in(['log', 'msg91'])],
            'sms_provider' => ['sometimes', 'required', 'string', 'max:30'],
            'sms_sender_id' => ['sometimes', 'nullable', 'string', 'max:15'],
            'sms_route' => ['sometimes', 'nullable', 'string', 'max:5'],
            'sms_country_code' => ['sometimes', 'required', 'string', 'max:5'],
            'sms_auth_key' => ['sometimes', 'nullable', 'string', 'max:120'],
            'sms_otp_length' => ['sometimes', 'required', 'integer', 'min:4', 'max:8'],
            'sms_otp_ttl' => ['sometimes', 'required', 'integer', 'min:60', 'max:1800'],

            // Email / SMTP gateway (SRS §19)
            'mail_driver' => ['sometimes', 'required', Rule::in(['log', 'smtp'])],
            'mail_host' => ['sometimes', 'nullable', 'string', 'max:120'],
            'mail_port' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:65535'],
            'mail_username' => ['sometimes', 'nullable', 'string', 'max:180'],
            'mail_password' => ['sometimes', 'nullable', 'string', 'max:180'],
            'mail_encryption' => ['sometimes', 'required', Rule::in(['tls', 'ssl', 'none'])],
            'mail_from_address' => ['sometimes', 'nullable', 'email', 'max:180'],
            'mail_from_name' => ['sometimes', 'nullable', 'string', 'max:120'],

            // AI Features (SRS §22)
            'ai_provider' => ['sometimes', 'required', Rule::in(['local', 'openai', 'anthropic'])],
            'ai_api_key' => ['sometimes', 'nullable', 'string', 'max:200'],
            'ai_model' => ['sometimes', 'nullable', 'string', 'max:80'],
            'ai_lead_time_days' => ['sometimes', 'required', 'integer', 'min:0', 'max:180'],

            // Loyalty programme (SRS §21)
            'loyalty_enabled' => ['sometimes', 'required', 'boolean'],
            'loyalty_points_per_amount' => ['sometimes', 'required', 'numeric', 'min:0', 'max:1000'],
            'loyalty_earn_amount_unit' => ['sometimes', 'required', 'numeric', 'min:1'],
            'loyalty_redeem_value' => ['sometimes', 'required', 'numeric', 'min:0'],
            'loyalty_min_redeem_points' => ['sometimes', 'required', 'integer', 'min:0'],
            'loyalty_min_bill_amount' => ['sometimes', 'required', 'numeric', 'min:0'],
            'loyalty_max_redeem_percent' => ['sometimes', 'required', 'numeric', 'min:0', 'max:100'],
        ];
        foreach (Setting::SMS_TEMPLATE_KEYS as $key) {
            $rules[$key] = ['sometimes', 'required', 'string', 'max:500'];
        }
        foreach (array_keys(Setting::NUMBER_PREFIX_DEFAULTS) as $key) {
            $rules[$key] = ['sometimes', 'required', 'string', 'max:10', 'alpha_dash'];
        }

        $validated = $request->validate($rules);

        foreach ($validated as $key => $value) {
            // Settings are stored as text. A validated boolean would coerce to
            // "1"/"" — store the explicit "0" instead, so a disabled flag reads
            // the same whether it was defaulted or saved.
            Setting::set($key, is_bool($value) ? ($value ? '1' : '0') : $value);
        }

        return response()->json([
            'data' => Setting::allWithDefaults(),
        ]);
    }
}
