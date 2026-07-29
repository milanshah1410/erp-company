<?php

namespace App\Models;

use App\Models\Concerns\LogsAuditActivity;
use App\Models\Scopes\TenantScope;
use App\Support\EnabledModules;
use App\Support\TenantContext;
use App\Support\TenantMenuAccess;
use App\Support\TenantStorage;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password', 'phone', 'avatar', 'is_active', 'tenant_id', 'current_company_id', 'current_branch_id'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, LogsAuditActivity, Notifiable;

    /**
     * Users are tenant-owned, but the tenant scope is NOT fail-closed here:
     * Sanctum resolves the user from the bearer token (and login looks users up
     * by email) before any tenant context exists, so the scope must no-op when
     * unset. Once ResolveTenant sets the context, user listings are scoped.
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope(failClosed: false));

        static::creating(function (User $user): void {
            if ($user->tenant_id === null) {
                $context = app(TenantContext::class);
                if ($context->has()) {
                    $user->tenant_id = $context->id();
                }
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'company_user');
    }

    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'branch_user');
    }

    public function currentCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'current_company_id');
    }

    public function currentBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'current_branch_id');
    }

    /**
     * The authenticated-session payload returned by /me and /context/switch:
     * identity, roles/permissions, active company/branch, and the full list
     * of companies/branches this user may switch into.
     */
    public function sessionPayload(): array
    {
        $isSuperAdmin = $this->hasRole(['super-admin', 'admin']);

        $companies = ($isSuperAdmin ? Company::query() : $this->companies())
            ->where('is_active', true)->orderBy('name')->get(['companies.id', 'companies.name']);
        $branches = ($isSuperAdmin ? Branch::query() : $this->branches())
            ->where('is_active', true)->orderBy('name')->get(['branches.id', 'branches.name', 'branches.company_id']);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            // Signed URL so the top bar can show the profile photo directly.
            'avatar_url' => TenantStorage::temporaryUrl($this->avatar),
            'roles' => $this->getRoleNames(),
            'permissions' => $this->effectivePermissionNames(),
            // The tenant's effective module set (onboarding choice ∩ plan), so the
            // SPA can hide module-specific screens the way MenuController hides
            // their menu rows. null = no selection on record → nothing is gated.
            'modules' => EnabledModules::forTenant($this->tenant_id),
            'current_company' => $this->currentCompany ? [
                'id' => $this->currentCompany->id,
                'name' => $this->currentCompany->name,
                'currency_code' => $this->currentCompany->currency_code,
                'currency_symbol' => $this->currentCompany->currency_symbol,
                'financial_year_start_month' => (int) $this->currentCompany->financial_year_start_month,
            ] : null,
            'current_branch' => $this->currentBranch ? [
                'id' => $this->currentBranch->id,
                'name' => $this->currentBranch->name,
            ] : null,
            'companies' => $companies,
            'branches' => $branches,
        ];
    }

    /**
     * The permissions the SPA should act on: what this user's roles grant, minus
     * anything the workspace is not entitled to — modules it does not have, and
     * screens the platform operator revoked.
     *
     * The grants themselves stay in the database (restoring a module or a screen
     * restores them), and this is the same subtraction CheckPermission enforces —
     * so a button the UI offers is never one the API then refuses, and the router
     * redirects instead of opening a screen whose every call 403s.
     *
     * @return Collection<int, string>
     */
    private function effectivePermissionNames(): Collection
    {
        $names = $this->getAllPermissions()->pluck('name');

        $enabled = EnabledModules::forTenant($this->tenant_id);
        $revoked = TenantMenuAccess::revokedGroups($this->tenant_id);

        if ($enabled === null && $revoked === []) {
            return $names;
        }

        $map = EnabledModules::permissionGroupMap();

        return $names->reject(
            fn (string $name) => ! EnabledModules::groupAvailable(EnabledModules::groupOf($name), $enabled, $map)
                || TenantMenuAccess::isRevoked($name, $revoked)
        )->values();
    }
}
