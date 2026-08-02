<?php

declare(strict_types=1);

namespace App\Modules\Industry\Stationery\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\SalesOrder;
use App\Modules\Industry\Stationery\Http\Requests\InstitutionRequest;
use App\Modules\Industry\Stationery\Models\ClassKit;
use App\Modules\Industry\Stationery\Models\Institution;
use Illuminate\Http\Request;

/**
 * The institution register — every school, college and office the shop supplies.
 *
 * The row is built around what a shop needs to know in April, not around what an
 * address book would hold: how many students, whose list it is, what concession
 * was promised to parents, whether the accounts office demands a purchase-order
 * number, and how many of this year's class lists are actually ready to sell
 * from. A school with eight hundred students and two published lists is the
 * loudest thing on this screen, and it should be.
 */
class InstitutionController extends Controller
{
    public function index(Request $request)
    {
        $query = Institution::query()->with('customer:id,code,name,phone');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('board', 'like', "%{$search}%")
                    ->orWhere('contact_person', 'like', "%{$search}%")
                    ->orWhere('contact_phone', 'like', "%{$search}%");
            });
        }

        foreach (['type', 'board', 'academic_year', 'customer_id'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->query($filter));
            }
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        // The accounts the shop has actually been appointed to — what it dares
        // to buy deep stock for.
        if ($request->boolean('authorised')) {
            $query->where('is_authorised_vendor', true);
        }

        $query->orderBy('name');

        return response()->json(
            $query->paginate(min((int) $request->query('per_page', 25), 100))
                ->through(fn (Institution $i) => $this->row($i))->toArray()
            + ['summary' => $this->summary()],
        );
    }

    public function show(Institution $institution)
    {
        $institution->load([
            'customer:id,code,name,phone,email,credit_limit',
            'kits' => fn ($q) => $q->orderBy('academic_year', 'desc')->orderBy('class_label'),
        ]);

        return response()->json(['data' => $this->row($institution, detailed: true)]);
    }

    /**
     * The season for one school: its lists, and what has been supplied against
     * them.
     *
     * Kept as its own endpoint rather than folded into `show` because it is the
     * screen a shop opens in June to answer "where are we with Sunrise?" — the
     * question is about progress, not about the account.
     */
    public function season(Request $request, Institution $institution)
    {
        $year = (string) $request->query('academic_year', $institution->academic_year);

        $kits = $institution->kits()
            ->where('academic_year', $year)
            ->withCount('items')
            ->orderBy('class_label')
            ->get();

        $orders = SalesOrder::query()
            ->where('stn_institution_id', $institution->id)
            ->with('invoice:id,sales_order_id,invoice_number,status,total,paid_amount')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return response()->json([
            'institution' => [
                'id' => $institution->id,
                'code' => $institution->code,
                'name' => $institution->name,
                'academic_year' => $year,
            ],
            'kits' => $kits->map(fn (ClassKit $k) => [
                'id' => $k->id,
                'kit_number' => $k->kit_number,
                'class_label' => $k->class_label,
                'stream' => $k->stream,
                'status' => $k->status,
                'items_count' => (int) $k->items_count,
                'expected_students' => $k->expected_students,
                'is_mandatory_set' => (bool) $k->is_mandatory_set,
                'discount_percent' => (float) $k->discount_percent,
            ])->values(),
            'orders' => $orders->map(fn (SalesOrder $o) => [
                'id' => $o->id,
                'so_number' => $o->so_number,
                'order_date' => $o->order_date?->toDateString(),
                'status' => $o->status,
                'total' => (float) $o->total,
                'po_number' => $o->stn_po_number,
                'invoice_number' => $o->invoice?->invoice_number,
                'invoice_status' => $o->invoice?->status,
            ])->values(),
        ]);
    }

    public function store(InstitutionRequest $request)
    {
        $institution = Institution::create($request->validated() + [
            // Generated, never typed: it heads a booklist, a quotation and a
            // delivery challan, and it is what distinguishes the two schools in
            // town with the same saint's name.
            'code' => Institution::nextNumber(),
            'created_by' => $request->user()?->id,
        ]);

        return response()->json(
            ['data' => $this->row($institution->fresh('customer'), detailed: true)],
            201,
        );
    }

    public function update(InstitutionRequest $request, Institution $institution)
    {
        $institution->update($request->validated());

        return response()->json(['data' => $this->row($institution->fresh('customer'), detailed: true)]);
    }

    public function destroy(Institution $institution)
    {
        /**
         * A school with lists or orders behind it is history. Deleting it would
         * cascade the booklists away and orphan the orders raised against them
         * (the FK is nullOnDelete), so next April nobody could answer what was
         * supplied last year — which is the only reference the new list is built
         * from.
         */
        if ($institution->kits()->exists() || $institution->salesOrders()->exists()) {
            return response()->json([
                'message' => 'This school has booklists or orders against it. Mark it inactive instead of deleting it.',
            ], 422);
        }

        $institution->delete();

        return response()->json(['message' => 'Institution removed.']);
    }

    private function summary(): array
    {
        return [
            'institutions' => Institution::count(),
            'active' => Institution::where('is_active', true)->count(),
            'authorised' => Institution::where('is_authorised_vendor', true)->count(),
            'students' => (int) Institution::where('is_active', true)->sum('student_count'),
            'published_kits' => ClassKit::where('status', 'published')->count(),
        ];
    }

    private function row(Institution $i, bool $detailed = false): array
    {
        $base = [
            'id' => $i->id,
            'code' => $i->code,
            'name' => $i->name,
            'label' => $i->label(),
            'type' => $i->type,
            'board' => $i->board,
            'medium' => $i->medium,
            'academic_year' => $i->academic_year,
            'session_starts_on' => $i->session_starts_on?->toDateString(),
            'student_count' => $i->student_count,
            'customer_id' => $i->customer_id,
            'customer' => $i->customer?->name,
            'contact_person' => $i->contact_person,
            'contact_phone' => $i->contact_phone,
            'is_authorised_vendor' => (bool) $i->is_authorised_vendor,
            'parent_discount_percent' => (float) $i->parent_discount_percent,
            'school_discount_percent' => (float) $i->school_discount_percent,
            'requires_po' => (bool) $i->requires_po,
            'credit_days' => (int) $i->credit_days,
            'is_active' => (bool) $i->is_active,
            /**
             * Counted on read rather than stored. A shop's question in April is
             * "how much of this school is ready?", and a cached number that was
             * true yesterday is exactly the wrong answer to it.
             */
            'kits_count' => $i->kits()->where('academic_year', $i->academic_year)->count(),
            'published_kits_count' => $i->kits()
                ->where('academic_year', $i->academic_year)
                ->where('status', 'published')
                ->count(),
        ];

        if (! $detailed) {
            return $base;
        }

        return $base + [
            'contact_email' => $i->contact_email,
            'address' => $i->address,
            'notes' => $i->notes,
            'kits' => $i->relationLoaded('kits')
                ? $i->kits->map(fn (ClassKit $k) => [
                    'id' => $k->id,
                    'kit_number' => $k->kit_number,
                    'academic_year' => $k->academic_year,
                    'class_label' => $k->class_label,
                    'stream' => $k->stream,
                    'status' => $k->status,
                ])->values()
                : [],
        ];
    }
}
