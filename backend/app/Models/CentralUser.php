<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Platform / super-admin operator (landlord level). Distinct from tenant
 * users, which live inside each tenant database. Authenticates on the
 * `central` guard against the central database.
 */
class CentralUser extends Authenticatable
{
    use HasApiTokens, Notifiable;

    protected $connection = 'central';

    protected $table = 'central_users';

    protected $fillable = ['name', 'email', 'password', 'is_active'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }
}
