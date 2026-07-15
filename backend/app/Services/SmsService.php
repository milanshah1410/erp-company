<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Customer;
use App\Models\Setting;
use App\Models\SmsMessage;
use App\Models\SmsOtp;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * SMS gateway abstraction (SRS §18). Follows the same "works with no third-party
 * account" philosophy as the WhatsApp click-to-chat module: the default `log`
 * driver records every message as if sent (so the whole UI and audit trail work
 * out of the box), and switching the `sms_driver` setting to `msg91` turns on a
 * real MSG91 sender using the configured auth key + sender id.
 *
 * All provider/credential/template configuration lives in Settings so it is
 * tenant-editable from the app; the MSG91 auth key also falls back to the
 * MSG91_AUTH_KEY env var for deployments that prefer to keep it out of the DB.
 */
class SmsService
{
    private const MSG91_ENDPOINT = 'https://api.msg91.com/api/sendhttp.php';

    public function driver(): string
    {
        return Setting::get('sms_driver', 'log') ?: 'log';
    }

    /** Whether the active (non-log) driver has enough config to actually send. */
    public function configured(): bool
    {
        if ($this->driver() === 'log') {
            return true;
        }

        return $this->authKey() !== '';
    }

    private function authKey(): string
    {
        return (string) (Setting::get('sms_auth_key') ?: config('services.msg91.key', ''));
    }

    private function countryCode(): string
    {
        return preg_replace('/\D+/', '', Setting::get('sms_country_code', '91')) ?: '91';
    }

    /**
     * Send an SMS and log it. Never throws on a gateway failure — the failure is
     * captured on the returned SmsMessage row (status=failed, error=...) so the
     * business flow that triggered it (invoice, dispatch, reminder) is not
     * derailed by an SMS outage. Configuration errors (misconfigured driver) DO
     * throw so the operator notices.
     *
     * @param  array{customer_id?:int|null, template?:string, created_by?:int|null, log_body?:string}  $opts
     */
    public function send(string $phone, string $body, array $opts = []): SmsMessage
    {
        $normalized = $this->normalizePhone($phone);
        $driver = $this->driver();

        $status = 'sent';
        $providerMessageId = null;
        $error = null;

        if ($driver === 'msg91') {
            try {
                $providerMessageId = $this->sendViaMsg91($normalized, $body);
            } catch (RuntimeException $e) {
                // Missing credentials — a configuration problem, surface it.
                throw $e;
            } catch (\Throwable $e) {
                $status = 'failed';
                $error = Str::limit($e->getMessage(), 480);
            }
        }

        return SmsMessage::create([
            'customer_id' => $opts['customer_id'] ?? null,
            'phone' => $normalized,
            'template' => $opts['template'] ?? 'manual',
            // OTP bodies are stored with the code masked (log_body override).
            'body' => $opts['log_body'] ?? $body,
            'provider' => $driver,
            'status' => $status,
            'provider_message_id' => $providerMessageId,
            'error' => $error,
            'created_by' => $opts['created_by'] ?? null,
        ]);
    }

    /**
     * Post to MSG91's HTTP send API. Returns the gateway request id on success.
     * Throws RuntimeException when unconfigured, and a generic exception (caught
     * by send()) on a transport/gateway error.
     */
    private function sendViaMsg91(string $mobile, string $body): string
    {
        $authKey = $this->authKey();
        if ($authKey === '') {
            throw new RuntimeException('SMS driver is set to MSG91 but no auth key is configured. Set it under Settings → SMS Gateway (or the MSG91_AUTH_KEY env var).');
        }

        $response = Http::asForm()->timeout(15)->get(self::MSG91_ENDPOINT, [
            'authkey' => $authKey,
            'mobiles' => $mobile,
            'message' => $body,
            'sender' => Setting::get('sms_sender_id', 'ERPTXT'),
            'route' => Setting::get('sms_route', '4'),
            'country' => $this->countryCode(),
            'response' => 'json',
        ])->throw();

        // MSG91 returns either a bare request id or a small JSON envelope.
        $json = null;
        try {
            $json = $response->json();
        } catch (\Throwable) {
            // non-JSON body; fall through to raw text
        }

        if (is_array($json)) {
            $type = strtolower((string) ($json['type'] ?? 'success'));
            if ($type === 'error') {
                throw new \RuntimeException('MSG91 error: '.($json['message'] ?? 'unknown'));
            }

            return (string) ($json['message'] ?? $response->body());
        }

        return trim($response->body());
    }

    /**
     * Render a template from Settings, substituting {placeholders}. Unknown
     * placeholders are left untouched so a mis-typed template is obvious.
     *
     * @param  array<string,string|int|float>  $vars
     */
    public function render(string $settingKey, array $vars): string
    {
        $template = Setting::get($settingKey, Setting::OTHER_DEFAULTS[$settingKey] ?? '') ?? '';

        return preg_replace_callback('/\{(\w+)\}/', function ($m) use ($vars) {
            $key = $m[1];

            return array_key_exists($key, $vars) ? (string) $vars[$key] : $m[0];
        }, $template);
    }

    // ---------------------------------------------------------------- OTP ---

    private function otpLength(): int
    {
        return max(4, min(8, (int) Setting::get('sms_otp_length', '6')));
    }

    private function otpTtl(): int
    {
        return max(60, (int) Setting::get('sms_otp_ttl', '300'));
    }

    /**
     * Generate an OTP for a phone + purpose, deliver it over SMS, and return the
     * plain code (callers must NOT expose it — it is returned only so tests /
     * the log driver can surface it in non-production use if desired).
     */
    public function generateOtp(string $phone, string $purpose, ?Customer $customer = null): SmsOtp
    {
        $normalized = $this->normalizePhone($phone);

        // Invalidate any still-live OTPs for this phone+purpose.
        SmsOtp::query()
            ->where('phone', $normalized)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        $length = $this->otpLength();
        $code = str_pad((string) random_int(0, (10 ** $length) - 1), $length, '0', STR_PAD_LEFT);
        $ttl = $this->otpTtl();

        $tenantId = null;
        $context = app(TenantContext::class);
        if ($context->has()) {
            $tenantId = $context->id();
        }

        $otp = SmsOtp::create([
            'tenant_id' => $customer?->tenant_id ?? $tenantId,
            'customer_id' => $customer?->id,
            'phone' => $normalized,
            'purpose' => $purpose,
            'code_hash' => Hash::make($code),
            'attempts' => 0,
            'expires_at' => now()->addSeconds($ttl),
        ]);

        $body = $this->render('sms_tpl_otp', [
            'otp' => $code,
            'minutes' => (int) ceil($ttl / 60),
        ]);

        // Log the OTP send with the code masked out of the stored body.
        $this->send($normalized, $body, [
            'customer_id' => $customer?->id,
            'template' => 'otp',
            'log_body' => str_replace($code, str_repeat('•', strlen($code)), $body),
        ]);

        return $otp;
    }

    /**
     * Verify a code against the latest live OTP for phone + purpose. Consumes the
     * OTP on success; increments the attempt counter and locks after 5 tries.
     */
    public function verifyOtp(string $phone, string $purpose, string $code): bool
    {
        $normalized = $this->normalizePhone($phone);

        $otp = SmsOtp::query()
            ->where('phone', $normalized)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->orderByDesc('id')
            ->first();

        if (! $otp || $otp->isExpired() || $otp->attempts >= 5) {
            return false;
        }

        if (! Hash::check($code, $otp->code_hash)) {
            $otp->increment('attempts');

            return false;
        }

        $otp->forceFill(['consumed_at' => now()])->save();

        return true;
    }

    /**
     * Strip non-digits and prepend the default country code for bare local
     * numbers so the gateway resolves them (10-digit numbers get the default).
     */
    public function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        $cc = $this->countryCode();

        if (strlen($digits) <= 10) {
            $digits = $cc.$digits;
        }

        return $digits;
    }
}
