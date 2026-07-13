<?php

namespace App\Models;

use App\Models\Concerns\LogsAuditActivity;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password', 'phone', 'is_active', 'current_company_id', 'current_branch_id'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, LogsAuditActivity, Notifiable;

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
            'roles' => $this->getRoleNames(),
            'permissions' => $this->getAllPermissions()->pluck('name'),
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
}
