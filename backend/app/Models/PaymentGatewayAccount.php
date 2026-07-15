<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A tenant's configured online payment gateway (SRS §16). Credentials are
 * stored encrypted at rest; the {@see \App\Services\PaymentGateways\PaymentGatewayManager}
 * resolves the matching driver from the `gateway` key + this account's config.
 */
class PaymentGatewayAccount extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    protected $fillable = [
        'gateway', 'label', 'mode', 'credentials', 'is_active', 'is_default',
    ];

    protected $casts = [
        'credentials' => 'encrypted:array',
        'is_active' => 'boolean',
        'is_default' => 'boolean',
    ];

    /** Credentials must never be serialised back to the API. */
    protected $hidden = ['credentials'];

    public function transactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class);
    }

    /** Gateway metadata from config/payments.php for this account's gateway. */
    public function meta(): array
    {
        return config("payments.gateways.{$this->gateway}", []);
    }

    /** The base API endpoint for the account's current (test/live) mode. */
    public function endpoint(): ?string
    {
        return $this->meta()['endpoints'][$this->mode] ?? null;
    }

    /** A single credential value, or null when unset. */
    public function credential(string $key): ?string
    {
        return $this->credentials[$key] ?? null;
    }

    /** Which credential fields are still missing for this gateway. */
    public function missingCredentials(): array
    {
        $missing = [];
        foreach ($this->meta()['fields'] ?? [] as $key => $field) {
            if (($field['required'] ?? false) && empty($this->credential($key))) {
                $missing[] = $key;
            }
        }

        return $missing;
    }

    public function isConfigured(): bool
    {
        return $this->is_active && $this->missingCredentials() === [];
    }
}
