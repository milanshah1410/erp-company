<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PendingRegistration extends Model
{
    protected $connection = 'central';

    protected $fillable = [
        'company_name', 'admin_name', 'email', 'phone', 'password', 'subdomain',
        'plan_slug', 'verification_code', 'verification_token', 'verified_at',
        'status', 'tenant_id', 'error',
    ];

    protected $hidden = ['password', 'verification_code', 'verification_token'];

    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }
}
