<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PendingRegistration extends Model
{
    use \App\Models\Concerns\UsesCentralConnection;

    protected $fillable = [
        'company_name', 'subdomain', 'admin_name', 'email', 'phone', 'password',
        'plan_slug', 'config', 'verification_code', 'verification_token', 'verified_at',
        'status', 'tenant_id', 'error',
    ];

    protected $hidden = ['password', 'verification_code', 'verification_token'];

    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
            'password' => 'hashed',
            'config' => 'array',
            // Onboarding-automation progress, written phase by phase by
            // ProvisionTenant (App\Support\ProvisionSteps). Deliberately not
            // fillable: only the tracker writes it, via forceFill.
            'provision_steps' => 'array',
        ];
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }
}
