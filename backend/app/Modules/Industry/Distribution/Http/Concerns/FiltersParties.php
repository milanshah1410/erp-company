<?php

declare(strict_types=1);

namespace App\Modules\Industry\Distribution\Http\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The party filters Credit Control and Outstanding & Ageing both need.
 *
 * A distributor never looks at "all parties": they look at one salesman's book,
 * or Tuesday's beat, or the parties in one town, because that is the unit of
 * work — a collection round follows a route. Both screens therefore filter the
 * same way, and doing it in one place keeps them from drifting into two slightly
 * different definitions of "this salesman's outstanding".
 *
 * The salesman and beat filters go through `beat_customers`, since a customer
 * belongs to a salesman only by being a stop on their beat. That is a real
 * many-to-many: a party covered by two beats appears under both salesmen, which
 * is correct — either of them may be sent to collect.
 */
trait FiltersParties
{
    /**
     * Narrow a `customers` query by the shared party filters. Anything absent
     * from the request is simply not applied.
     *
     * @param  Builder<\App\Models\Customer>  $query
     */
    protected function applyPartyFilters(Builder $query, Request $request): void
    {
        if ($term = trim((string) $request->query('q', ''))) {
            $like = '%'.$term.'%';
            $query->where(function (Builder $q) use ($like) {
                $q->where('customers.name', 'like', $like)
                    ->orWhere('customers.code', 'like', $like)
                    ->orWhere('customers.company_name', 'like', $like)
                    ->orWhere('customers.phone', 'like', $like);
            });
        }

        if ($request->filled('customer_id')) {
            $query->where('customers.id', $request->integer('customer_id'));
        }

        if ($request->filled('customer_category_id')) {
            $query->where('customers.customer_category_id', $request->integer('customer_category_id'));
        }

        if ($city = trim((string) $request->query('city', ''))) {
            $query->where('customers.city', $city);
        }

        // A beat is a route; its salesman owns every stop on it.
        foreach (['beat_id' => 'beats.id', 'salesman_id' => 'beats.salesman_id'] as $param => $column) {
            if (! $request->filled($param)) {
                continue;
            }

            $value = $request->integer($param);
            $query->whereExists(function ($sub) use ($column, $value) {
                $sub->selectRaw('1')
                    ->from('beat_customers')
                    ->join('beats', 'beats.id', '=', 'beat_customers.beat_id')
                    ->whereColumn('beat_customers.customer_id', 'customers.id')
                    ->where($column, $value);
            });
        }

        if ($request->boolean('on_hold')) {
            $query->where('customers.credit_hold', true);
        }

        if ($request->boolean('only_with_limit')) {
            $query->where('customers.credit_limit', '>', 0);
        }

        /**
         * Inactive parties are hidden unless asked for. A closed shop with a
         * balance still matters to the books, but it is not who the morning's
         * collection calls go to, so it stays out of the default view.
         */
        if (! $request->boolean('include_inactive')) {
            $query->where('customers.is_active', true);
        }
    }
}
