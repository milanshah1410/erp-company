<?php

declare(strict_types=1);

namespace App\Modules\Industry\Gift\Services;

use App\Models\Customer;
use App\Models\Setting;
use App\Models\User;
use App\Modules\Industry\Gift\Models\GiftOccasion;
use App\Notifications\GiftOccasionUpcomingNotification;
use App\Services\SmsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Throwable;

/**
 * The occasion board, and the reminders that come off it.
 *
 * A gift shop's repeat business is entirely this: knowing that a customer has a
 * wedding anniversary in nine days, and saying so first. Everything here serves
 * that one sentence.
 *
 * Two audiences per reminder, and they are not the same message:
 *
 *  - **The counter** always hears about it, as a notification, because someone has
 *    to make the call and the shop's own staff are the ones who will sell
 *    something.
 *  - **The customer** hears about it only if the occasion says so
 *    (`notify_customer`) and only by SMS, which is skipped silently when no
 *    gateway is configured. Texting someone about their wedding anniversary is
 *    welcome; texting them about a date they asked the shop to keep quietly on
 *    file is not, which is why the switch is per occasion rather than per shop.
 *
 * `last_reminded_on` is stamped whichever audience was actually reached, and it is
 * what makes a yearly occasion remind once a year rather than every morning for a
 * week. It is stamped even when the SMS fails: the counter was still told, and
 * repeating the internal alert daily is how staff learn to ignore it.
 */
class GiftOccasionService
{
    /** The Settings key holding the outgoing reminder text. */
    public const SMS_TEMPLATE = 'sms_tpl_gift_occasion';

    /**
     * Roles that get told — the desks that would actually ring the customer, and
     * that hold `gift-occasions` in the role catalogue. Counter staff are
     * deliberately absent: they cannot open the register, so alerting them about a
     * record they may not read would be noise they can do nothing with.
     */
    private const RECIPIENT_ROLES = ['admin', 'super-admin', 'sales-manager', 'gift-store-manager'];

    public function __construct(private readonly SmsService $sms) {}

    /**
     * What is coming up, soonest first.
     *
     * Ordering is done in PHP rather than SQL on purpose: "soonest" is
     * next-occurrence, which is a day-and-month comparison that rolls over the new
     * year, and no portable SQL expression for it survives contact with three
     * database drivers. An occasion register is a few hundred rows per shop, so
     * this is the cheap side of the trade-off.
     *
     * @return Collection<int, GiftOccasion>
     */
    public function upcoming(int $withinDays = 30, ?Carbon $from = null): Collection
    {
        $from = ($from ?? Carbon::today())->copy()->startOfDay();

        return GiftOccasion::query()
            ->active()
            ->with('customer:id,name,phone,email')
            ->get()
            ->filter(function (GiftOccasion $occasion) use ($withinDays, $from) {
                $days = $occasion->daysUntil($from);

                return $days !== null && $days >= 0 && $days <= $withinDays;
            })
            ->sortBy(fn (GiftOccasion $occasion) => $occasion->daysUntil($from))
            ->values();
    }

    /**
     * Everything owed a reminder today — inside its own lead time and not yet
     * told about this occurrence.
     *
     * @return Collection<int, GiftOccasion>
     */
    public function due(?Carbon $from = null): Collection
    {
        $from = ($from ?? Carbon::today())->copy()->startOfDay();

        return GiftOccasion::query()
            ->active()
            ->with('customer:id,name,phone,email')
            ->get()
            ->filter(fn (GiftOccasion $occasion) => $occasion->reminderDue($from))
            ->sortBy(fn (GiftOccasion $occasion) => $occasion->daysUntil($from))
            ->values();
    }

    /**
     * Remind the shop (and the customer, where allowed) about one occasion.
     *
     * @return array{notified: int, sms: bool}
     */
    public function remind(GiftOccasion $occasion, ?Carbon $from = null): array
    {
        $days = max(0, (int) ($occasion->daysUntil($from) ?? 0));

        $recipients = $this->recipients();
        $notified = 0;

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new GiftOccasionUpcomingNotification($occasion, $days));
            $notified = $recipients->count();
        }

        $texted = $this->text($occasion, $days);

        $occasion->forceFill(['last_reminded_on' => Carbon::today()])->save();

        return ['notified' => $notified, 'sms' => $texted];
    }

    /**
     * Run the whole board. Returns how many occasions were reminded.
     */
    public function remindDue(?Carbon $from = null): int
    {
        $count = 0;

        foreach ($this->due($from) as $occasion) {
            $this->remind($occasion, $from);
            $count++;
        }

        return $count;
    }

    /**
     * Lift occasions out of the customer master.
     *
     * A shop that has been keying birthdays into the customer record for two years
     * should not have to key them again to use this register. Rows created here are
     * marked `source = customer_master` and matched on (customer, occasion, source),
     * so the import is idempotent and — the part that matters — never touches a row
     * somebody has since edited by hand: a manual row for the same customer and
     * occasion is left exactly as it is, and wins on the board.
     *
     * @return array{created: int, skipped: int}
     */
    public function importFromCustomers(?int $userId = null, int $remindDaysBefore = 7): array
    {
        $created = 0;
        $skipped = 0;

        Customer::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNotNull('date_of_birth')->orWhereNotNull('anniversary_date'))
            ->get(['id', 'name', 'date_of_birth', 'anniversary_date'])
            ->each(function (Customer $customer) use (&$created, &$skipped, $userId, $remindDaysBefore) {
                $dates = [
                    'birthday' => $customer->date_of_birth,
                    'anniversary' => $customer->anniversary_date,
                ];

                foreach ($dates as $occasion => $date) {
                    if (! $date) {
                        continue;
                    }

                    $exists = GiftOccasion::query()
                        ->where('customer_id', $customer->id)
                        ->where('occasion', $occasion)
                        ->exists();

                    if ($exists) {
                        $skipped++;

                        continue;
                    }

                    GiftOccasion::create([
                        'customer_id' => $customer->id,
                        // Blank person = the customer themself, which is exactly
                        // what a date on the customer record means.
                        'person_name' => null,
                        'occasion' => $occasion,
                        'occasion_date' => $date,
                        'is_recurring' => true,
                        'remind_days_before' => $remindDaysBefore,
                        'source' => 'customer_master',
                        'created_by' => $userId,
                    ]);

                    $created++;
                }
            });

        return ['created' => $created, 'skipped' => $skipped];
    }

    /**
     * Text the customer, if they are to be texted at all.
     *
     * Never throws: a shop's reminder run must not stop because a gateway is down
     * or a phone number is malformed — the counter has still been told, which is
     * the half of this that sells something.
     */
    private function text(GiftOccasion $occasion, int $days): bool
    {
        if (! $occasion->notify_customer || ! $this->sms->configured()) {
            return false;
        }

        $phone = trim((string) ($occasion->customer?->phone ?? ''));

        if ($phone === '') {
            return false;
        }

        $body = $this->sms->render(self::SMS_TEMPLATE, [
            'name' => (string) ($occasion->customer?->name ?? ''),
            'person' => $occasion->personLabel(),
            'occasion' => strtolower($occasion->occasionLabel()),
            'days' => $days,
            'date' => (string) ($occasion->nextOccurrence()?->format('d M') ?? ''),
            'business' => (string) (Setting::get('company_name', 'our shop') ?? 'our shop'),
        ]);

        try {
            $this->sms->send($phone, $body, [
                'customer_id' => $occasion->customer_id,
                'template' => 'gift_occasion',
            ]);
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    /**
     * Who hears about it. Falls back to nobody rather than to everybody — a shop
     * with no sales desk configured should get a quiet run, not a broadcast.
     *
     * The role list is intersected with the roles that actually exist first:
     * Spatie's `role` scope throws on a name it cannot resolve, and the gift
     * counter's own roles are only present in a workspace whose tenant roles have
     * been seeded since this vertical shipped.
     *
     * @return Collection<int, User>
     */
    private function recipients(): Collection
    {
        $roles = Role::query()
            ->whereIn('name', self::RECIPIENT_ROLES)
            ->where('guard_name', 'web')
            ->pluck('name')
            ->all();

        if ($roles === []) {
            return new Collection;
        }

        return User::role($roles)->where('is_active', true)->get();
    }
}
