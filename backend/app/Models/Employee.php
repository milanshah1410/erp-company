<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Employee extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'user_id', 'department_id', 'employee_code', 'first_name', 'last_name',
        'email', 'phone', 'designation', 'employment_type',
        'date_of_joining', 'date_of_birth', 'gender', 'address', 'is_active',
    ];

    protected $casts = [
        'date_of_joining' => 'date',
        'date_of_birth' => 'date',
        'is_active' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function salaries(): HasMany
    {
        return $this->hasMany(Salary::class);
    }

    /** "EMP001 — Jane Doe" for pickers and dropdowns. */
    public function fullName(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }
}
