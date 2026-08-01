<?php

declare(strict_types=1);

namespace App\Modules\Industry\FoodBeverage\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A membership tier a shop sells: ₹1,000 for a year of 10% off.
 *
 * Distinct from Loyalty, which already ships and works the other way round —
 * loyalty is earned by spending, a membership is bought up front and entitles the
 * holder from the first sip. The two compose rather than compete:
 * `loyalty_multiplier` is what the Loyalty module reads when it accrues points
 * for a member, so a shop can run both without either overriding the other.
 *
 * Validity is in days, not months. "90 days" is what a café means by a quarterly
 * card, and calendar-month arithmetic on a card sold on the 31st is a bug waiting
 * to be filed.
 */
class MembershipPlan extends Model
{
    use BelongsToTenant, LogsAuditActivity;

    protected $fillable = [
        'code', 'name', 'description', 'price', 'duration_days',
        'discount_percent', 'loyalty_multiplier', 'benefits', 'is_active',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'duration_days' => 'integer',
        'discount_percent' => 'decimal:2',
        'loyalty_multiplier' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function memberships(): HasMany
    {
        return $this->hasMany(CustomerMembership::class, 'membership_plan_id');
    }
}
