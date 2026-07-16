<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * Platform / super-admin operator (landlord level). Distinct from tenant
 * users, which live inside each tenant database. Authenticates on the
 * `central` guard against the central database.
 *
 * RBAC (SaaS-owner, Level 1): roles/permissions are Spatie records on the
 * `central` guard. The guard keeps them fully separate from the tenant `web`
 * roles that share the same tables. Because Spatie's teams feature is on
 * (team_foreign_key = tenant_id) and the model_has_roles pivot's team key is
 * part of its primary key — so it can never be NULL — central operators are
 * pinned to the reserved sentinel team id below (real tenant ids start at 1).
 * Every central-side role read/write runs under it — see
 * App\Http\Middleware\ResolveCentralContext.
 */
class CentralUser extends Authenticatable
{
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    /**
     * Reserved Spatie "team" id for platform operators. Not a real tenant
     * (auto-increment tenant ids are >= 1), so central role assignments never
     * collide with any tenant's.
     */
    public const TEAM_ID = 0;

    /**
     * Fix the guard so HasRoles never has to infer it, and role/permission
     * assignments always land on the `central` guard.
     */
    protected string $guard_name = 'central';

    protected $table = 'central_users';

    /**
     * Pin to the landlord connection. Driven by config so the test suite (a
     * single shared sqlite connection) and any future split-DB deployment can
     * both override it; production keeps the dedicated `central` connection.
     */
    public function getConnectionName(): ?string
    {
        return config('saas.central_connection', 'central');
    }

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

    /**
     * Identity + flattened roles/permissions returned by the central /me and
     * /login endpoints. Drives the platform admin panel's menu + button gating.
     */
    public function sessionPayload(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'is_active' => (bool) $this->is_active,
            'roles' => $this->getRoleNames(),
            'permissions' => $this->getAllPermissions()->pluck('name'),
        ];
    }
}
