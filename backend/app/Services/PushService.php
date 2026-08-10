<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PushDevice;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Push notifications to registered handsets (retail base §10 "Firebase push").
 *
 * Follows the same driver seam as SmsService and MailService, for the same
 * reason: the default `log` driver records every push as if sent, so the whole
 * flow — device registration, the notification channel, the settings screen —
 * works out of the box with no Firebase project. Switching `push_driver` to
 * `fcm` turns on a real sender.
 *
 * **FCM HTTP v1, not the legacy API.** The legacy `fcm/send` endpoint with its
 * single server key was shut down by Google in 2024, so the only working path is
 * v1: sign a JWT with the service account's private key, exchange it for an
 * OAuth access token, then POST one message per token. That is more work than a
 * server key and it is unavoidable — a "server key" field here would be a field
 * that cannot ever succeed.
 *
 * Access tokens last an hour and are cached for slightly less, because minting
 * one costs a round trip and an RSA signature and a digest going to twenty
 * handsets must not do that twenty times.
 *
 * Nothing here throws on a delivery failure. A push is the least important copy
 * of any message — the same notification is already in the bell and usually in an
 * inbox — so a Firebase outage must never derail the business flow that triggered
 * it. Configuration errors do throw, so an operator finds out.
 */
class PushService
{
    private const OAUTH_ENDPOINT = 'https://oauth2.googleapis.com/token';

    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    /** Tokens live an hour; 55 minutes leaves room for a slow request. */
    private const TOKEN_TTL = 3300;

    public function driver(): string
    {
        return Setting::get('push_driver', 'log') ?: 'log';
    }

    /** Whether the active driver has enough config to actually send. */
    public function configured(): bool
    {
        if ($this->driver() === 'log') {
            return true;
        }

        return $this->projectId() !== '' && $this->credentials() !== null;
    }

    /**
     * Send to every active handset a user has registered.
     *
     * @param  array<string, string>  $data  Extra key/values delivered to the app.
     * @return array{sent:int, failed:int, devices:int}
     */
    public function toUser(User $user, string $title, string $body, array $data = []): array
    {
        $devices = PushDevice::query()->active()->where('user_id', $user->id)->get();

        return $this->deliver($devices, $title, $body, $data);
    }

    /** Send to one named device — used by the "send a test" button. */
    public function toDevice(PushDevice $device, string $title, string $body, array $data = []): array
    {
        return $this->deliver(collect([$device]), $title, $body, $data);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, PushDevice>  $devices
     * @return array{sent:int, failed:int, devices:int}
     */
    private function deliver($devices, string $title, string $body, array $data): array
    {
        if ($devices->isEmpty()) {
            return ['sent' => 0, 'failed' => 0, 'devices' => 0];
        }

        $driver = $this->driver();
        $sent = 0;
        $failed = 0;

        // One access token for the whole batch, not one per handset.
        $accessToken = $driver === 'fcm' ? $this->accessToken() : null;

        foreach ($devices as $device) {
            if ($driver === 'log') {
                Log::info('[push:log] '.$title, [
                    'user_id' => $device->user_id,
                    'device' => $device->label(),
                    'platform' => $device->platform,
                    'body' => $body,
                    'data' => $data,
                ]);
                $device->forceFill(['last_used_at' => now()])->save();
                $sent++;

                continue;
            }

            try {
                $this->sendViaFcm($accessToken, $device, $title, $body, $data);
                $device->forceFill(['last_used_at' => now()])->save();
                $sent++;
            } catch (DeadPushTokenException $e) {
                // FCM says this handset is gone — reinstalled, restored, or the
                // app was removed. Switch it off so the next digest does not try.
                $device->markDead($e->getMessage());
                $failed++;
            } catch (Throwable $e) {
                // A transient failure. Recorded on the row, never re-thrown.
                $device->forceFill([
                    'last_failed_at' => now(),
                    'last_error' => mb_substr($e->getMessage(), 0, 250),
                ])->save();
                $failed++;
            }
        }

        return ['sent' => $sent, 'failed' => $failed, 'devices' => $devices->count()];
    }

    /**
     * One message, one token.
     *
     * `data` values must be strings — FCM rejects a payload with a number or a
     * boolean in the data map, which is the single most common way a working
     * integration starts failing after somebody adds a count to a notification.
     */
    private function sendViaFcm(?string $accessToken, PushDevice $device, string $title, string $body, array $data): void
    {
        if (! $accessToken) {
            throw new RuntimeException('Push is set to Firebase but no access token could be obtained.');
        }

        $response = Http::withToken($accessToken)
            ->acceptJson()
            ->timeout(15)
            ->post("https://fcm.googleapis.com/v1/projects/{$this->projectId()}/messages:send", [
                'message' => [
                    'token' => $device->token,
                    'notification' => ['title' => $title, 'body' => $body],
                    'data' => array_map(fn ($v) => (string) $v, $data),
                    'android' => ['priority' => 'high'],
                    'apns' => ['headers' => ['apns-priority' => '10']],
                ],
            ]);

        if ($response->successful()) {
            return;
        }

        $error = (string) ($response->json('error.status') ?: $response->json('error.message') ?: 'HTTP '.$response->status());

        /**
         * These two mean the token will never work again, as opposed to "try
         * later". Distinguishing them is what keeps the device table from
         * accumulating handsets that fail on every send for ever.
         */
        if (in_array($error, ['UNREGISTERED', 'NOT_FOUND', 'INVALID_ARGUMENT'], true) || $response->status() === 404) {
            throw new DeadPushTokenException($error);
        }

        throw new RuntimeException("FCM rejected the message: {$error}");
    }

    // -----------------------------------------------------------------------
    // OAuth
    // -----------------------------------------------------------------------

    private function projectId(): string
    {
        $configured = trim((string) Setting::get('push_project_id', ''));
        if ($configured !== '') {
            return $configured;
        }

        // The service-account JSON already names the project; asking for it twice
        // is a chance for the two to disagree.
        return (string) ($this->credentials()['project_id'] ?? '');
    }

    /** The service-account JSON, parsed. Null when unset or unparseable. */
    private function credentials(): ?array
    {
        $raw = (string) (Setting::get('push_credentials_json', '') ?: config('services.fcm.credentials', ''));
        if (trim($raw) === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) && isset($decoded['client_email'], $decoded['private_key']) ? $decoded : null;
    }

    /**
     * An OAuth access token for the messaging scope, cached per project.
     *
     * Cached in the *application* cache rather than TenantCache: the credential
     * is the tenant's, but the cache key is the service account's email, which is
     * already unique to it. Using the tenant cache would mean a version bump on
     * any settings change silently discarding a perfectly good token.
     */
    private function accessToken(): ?string
    {
        $creds = $this->credentials();
        if (! $creds) {
            throw new RuntimeException('Push is set to Firebase but no service-account JSON has been saved.');
        }

        $key = 'push_fcm_token_'.sha1($creds['client_email']);

        return Cache::remember($key, self::TOKEN_TTL, function () use ($creds) {
            $jwt = $this->signedJwt($creds);

            $response = Http::asForm()->timeout(15)->post(self::OAUTH_ENDPOINT, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);

            if (! $response->successful()) {
                throw new RuntimeException('Firebase rejected the service account: '
                    .($response->json('error_description') ?: 'HTTP '.$response->status()));
            }

            return (string) $response->json('access_token');
        });
    }

    /** RS256-signed assertion, per Google's service-account flow. */
    private function signedJwt(array $creds): string
    {
        $now = time();
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $claims = [
            'iss' => $creds['client_email'],
            'scope' => self::SCOPE,
            'aud' => self::OAUTH_ENDPOINT,
            'iat' => $now,
            'exp' => $now + 3600,
        ];

        $input = $this->base64Url(json_encode($header)).'.'.$this->base64Url(json_encode($claims));

        $key = openssl_pkey_get_private($creds['private_key']);
        if ($key === false) {
            throw new RuntimeException('The service-account private key could not be read.');
        }

        $signature = '';
        if (! openssl_sign($input, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Could not sign the Firebase request.');
        }

        return $input.'.'.$this->base64Url($signature);
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
