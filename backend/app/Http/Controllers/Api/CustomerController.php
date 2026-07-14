<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Http\Resources\SalesOrderResource;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\SalesOrder;
use App\Models\Scopes\TenantScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class CustomerController extends Controller
{
    public function index(Request $request)
    {
        $query = Customer::query()->with('category:id,name,default_price_level');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('company_name', 'like', "%{$search}%");
            });
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($request->filled('customer_category_id')) {
            $query->where('customer_category_id', $request->query('customer_category_id'));
        }

        if ($request->filled('type')) {
            $query->where('type', $request->query('type'));
        }

        $sort = $request->query('sort', 'created_at');
        $direction = $request->query('direction', 'desc');
        if (in_array($sort, ['name', 'code', 'created_at'], true)) {
            $query->orderBy($sort, $direction === 'asc' ? 'asc' : 'desc');
        }

        $perPage = min((int) $request->query('per_page', 15), 100);

        return CustomerResource::collection($query->paginate($perPage));
    }

    public function store(CustomerRequest $request)
    {
        $customer = Customer::create($request->validated());

        return (new CustomerResource($customer))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Customer $customer)
    {
        return new CustomerResource($customer->load('category'));
    }

    public function update(CustomerRequest $request, Customer $customer)
    {
        $customer->update($request->validated());

        return new CustomerResource($customer);
    }

    public function destroy(Customer $customer)
    {
        if ($customer->salesOrders()->exists()) {
            return response()->json([
                'message' => 'This customer has sales orders and cannot be deleted. Mark it inactive instead.',
            ], 422);
        }
        $customer->delete();

        return response()->json(['message' => 'Customer deleted.']);
    }

    /**
     * Enable/disable the customer's B2B portal login and (re)set its password
     * (SRS §14). Portal-enabled accounts must have a globally-unique email so
     * they can be resolved at login before any tenant context exists.
     */
    public function setPortalAccess(Request $request, Customer $customer)
    {
        $data = $request->validate([
            'portal_enabled' => ['required', 'boolean'],
            'password' => ['nullable', 'string', 'min:6', 'max:100'],
        ]);

        if ($data['portal_enabled']) {
            if (empty($customer->email)) {
                return response()->json(['message' => 'A customer email is required to enable portal login.'], 422);
            }

            $emailTaken = Customer::query()
                ->withoutGlobalScope(TenantScope::class)
                ->where('email', $customer->email)
                ->where('portal_enabled', true)
                ->whereKeyNot($customer->id)
                ->exists();
            if ($emailTaken) {
                return response()->json(['message' => 'Another portal account already uses this email address.'], 422);
            }

            if (empty($customer->portal_password) && empty($data['password'])) {
                return response()->json(['message' => 'Set a password to enable portal login.'], 422);
            }
        }

        $customer->portal_enabled = $data['portal_enabled'];
        if (! empty($data['password'])) {
            $customer->portal_password = Hash::make($data['password']);
        }
        $customer->save();

        // Revoke any live portal sessions when access is turned off.
        if (! $data['portal_enabled']) {
            $customer->tokens()->delete();
        }

        return new CustomerResource($customer->fresh());
    }

    /**
     * Purchase history for a customer: paginated sales orders plus a
     * summary block including the live outstanding balance.
     */
    public function purchaseHistory(Request $request, Customer $customer)
    {
        $base = SalesOrder::query()->where('customer_id', $customer->id);

        $summary = [
            'total_orders' => (clone $base)->count(),
            'total_value' => (float) (clone $base)->whereNot('status', 'cancelled')->sum('total'),
            'open_orders' => (clone $base)->whereIn('status', ['draft', 'confirmed'])->count(),
            'open_value' => (float) (clone $base)->whereIn('status', ['draft', 'confirmed'])->sum('total'),
            'last_order_date' => (clone $base)->max('order_date'),
            'credit_limit' => (float) $customer->credit_limit,
            'outstanding_balance' => $customer->outstandingBalance(),
        ];

        $query = $base->with(['warehouse:id,name', 'invoice']);
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        $query->orderByDesc('id');
        $perPage = min((int) $request->query('per_page', 15), 100);

        return SalesOrderResource::collection($query->paginate($perPage))
            ->additional(['summary' => $summary]);
    }

    /**
     * Upcoming customer birthdays & anniversaries within ?days (default 30),
     * ordered by how soon they fall. Powers the "occasions" wish screen and
     * feeds the daily reminder command. Computed in PHP so it is DB-agnostic.
     */
    public function occasions(Request $request)
    {
        $days = min(max((int) $request->query('days', 30), 1), 366);
        $today = now()->startOfDay();
        $horizon = (clone $today)->addDays($days);
        $type = $request->query('type'); // optional: birthday | anniversary

        $result = [];
        Customer::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNotNull('date_of_birth')->orWhereNotNull('anniversary_date'))
            ->get(['id', 'code', 'name', 'phone', 'date_of_birth', 'anniversary_date'])
            ->each(function (Customer $c) use ($today, $horizon, $type, &$result) {
                $occasions = ['birthday' => $c->date_of_birth, 'anniversary' => $c->anniversary_date];
                foreach ($occasions as $kind => $date) {
                    if (! $date || ($type && $type !== $kind)) {
                        continue;
                    }
                    $next = $date->copy()->setDate((int) $today->year, (int) $date->month, (int) $date->day);
                    if ($next->lt($today)) {
                        $next->addYear();
                    }
                    if ($next->gt($horizon)) {
                        continue;
                    }
                    $result[] = [
                        'customer_id' => $c->id,
                        'code' => $c->code,
                        'name' => $c->name,
                        'phone' => $c->phone,
                        'type' => $kind,
                        'date' => $date->toDateString(),
                        'next_date' => $next->toDateString(),
                        'days_away' => (int) $today->diffInDays($next),
                        'years' => (int) ($next->year - $date->year),
                    ];
                }
            });

        usort($result, fn ($a, $b) => $a['days_away'] <=> $b['days_away']);

        return response()->json(['data' => $result]);
    }

    /**
     * Customer ledger: a chronological, running-balance statement built from
     * the opening balance (debit), invoices (debit), payments (credit) and
     * approved credit notes (credit). Optional ?from / ?to date filters.
     */
    public function ledger(Request $request, Customer $customer)
    {
        $from = $request->query('from');
        $to = $request->query('to');
        $dateWindow = fn ($q, string $column) => $q
            ->when($from, fn ($qq) => $qq->whereDate($column, '>=', $from))
            ->when($to, fn ($qq) => $qq->whereDate($column, '<=', $to));

        $entries = [];

        // Invoices raise the receivable (debit).
        $dateWindow($customer->invoices(), 'invoice_date')
            ->get(['id', 'invoice_number', 'invoice_date', 'total'])
            ->each(function ($inv) use (&$entries) {
                $entries[] = [
                    'date' => optional($inv->invoice_date)->toDateString(),
                    'type' => 'invoice',
                    'reference' => $inv->invoice_number,
                    'description' => 'Sales Invoice',
                    'debit' => (float) $inv->total,
                    'credit' => 0.0,
                ];
            });

        // Payments reduce the receivable (credit).
        $dateWindow(
            Payment::query()
                ->whereIn('invoice_id', $customer->invoices()->select('id'))
                ->with('invoice:id,invoice_number'),
            'payment_date'
        )->get(['id', 'invoice_id', 'amount', 'mode', 'payment_date'])
            ->each(function ($pay) use (&$entries) {
                $entries[] = [
                    'date' => optional($pay->payment_date)->toDateString(),
                    'type' => 'payment',
                    'reference' => $pay->invoice?->invoice_number,
                    'description' => 'Payment ('.$pay->mode.')',
                    'debit' => 0.0,
                    'credit' => (float) $pay->amount,
                ];
            });

        // Approved credit notes reduce the receivable (credit).
        $dateWindow($customer->salesReturns()->where('status', 'approved'), 'return_date')
            ->get(['id', 'credit_note_number', 'return_date', 'total'])
            ->each(function ($cn) use (&$entries) {
                $entries[] = [
                    'date' => optional($cn->return_date)->toDateString(),
                    'type' => 'credit_note',
                    'reference' => $cn->credit_note_number,
                    'description' => 'Credit Note',
                    'debit' => 0.0,
                    'credit' => (float) $cn->total,
                ];
            });

        // Chronological order; entries without a date float to the top.
        usort($entries, fn ($a, $b) => ($a['date'] ?? '') <=> ($b['date'] ?? ''));

        $opening = (float) $customer->opening_balance;
        $running = $opening;
        foreach ($entries as &$entry) {
            $running = round($running + $entry['debit'] - $entry['credit'], 2);
            $entry['balance'] = $running;
        }
        unset($entry);

        // Prepend the opening balance row as the statement's starting point.
        if (! ($from)) {
            array_unshift($entries, [
                'date' => optional($customer->created_at)->toDateString(),
                'type' => 'opening',
                'reference' => null,
                'description' => 'Opening Balance',
                'debit' => $opening,
                'credit' => 0.0,
                'balance' => $opening,
            ]);
        }

        $totalDebit = round(array_sum(array_column($entries, 'debit')), 2);
        $totalCredit = round(array_sum(array_column($entries, 'credit')), 2);

        return response()->json([
            'data' => $entries,
            'summary' => [
                'opening_balance' => $opening,
                'total_debit' => $totalDebit,
                'total_credit' => $totalCredit,
                'closing_balance' => $running,
                'outstanding_balance' => $customer->outstandingBalance(),
                'credit_limit' => (float) $customer->credit_limit,
                'available_credit' => $customer->availableCredit(),
            ],
        ]);
    }

    /**
     * Payment history: every payment recorded against this customer's
     * invoices, most recent first, with a totals summary.
     */
    public function paymentHistory(Request $request, Customer $customer)
    {
        $base = Payment::query()->whereIn('invoice_id', $customer->invoices()->select('id'));

        $summary = [
            'total_paid' => (float) (clone $base)->sum('amount'),
            'payment_count' => (clone $base)->count(),
            'last_payment_date' => (clone $base)->max('payment_date'),
        ];

        $query = (clone $base)->with('invoice:id,invoice_number');
        if ($request->filled('mode')) {
            $query->where('mode', $request->query('mode'));
        }
        $query->orderByDesc('payment_date')->orderByDesc('id');

        $perPage = min((int) $request->query('per_page', 15), 100);
        $payments = $query->paginate($perPage);

        $payments->getCollection()->transform(fn ($p) => [
            'id' => $p->id,
            'invoice_id' => $p->invoice_id,
            'invoice_number' => $p->invoice?->invoice_number,
            'amount' => (float) $p->amount,
            'mode' => $p->mode,
            'payment_date' => optional($p->payment_date)->toDateString(),
            'notes' => $p->notes,
        ]);

        return response()->json([
            'data' => $payments->items(),
            'meta' => [
                'current_page' => $payments->currentPage(),
                'last_page' => $payments->lastPage(),
                'per_page' => $payments->perPage(),
                'total' => $payments->total(),
            ],
            'summary' => $summary,
        ]);
    }
}
