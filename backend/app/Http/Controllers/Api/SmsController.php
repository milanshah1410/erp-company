<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SmsMessageResource;
use App\Models\Customer;
use App\Models\DispatchPlan;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\SmsMessage;
use App\Models\Tenant;
use App\Services\SmsService;
use App\Support\TenantContext;
use Illuminate\Http\Request;

/**
 * SMS integration (SRS §18): OTP, invoice, dispatch, payment reminder and
 * festival-greeting sends, plus the outbound message log. All sends go through
 * SmsService, so they respect the configured driver (log by default, MSG91 when
 * enabled) and every message lands in the log.
 */
class SmsController extends Controller
{
    public function __construct(private readonly SmsService $sms)
    {
    }

    /** Outbound SMS log, newest first. */
    public function index(Request $request)
    {
        $query = SmsMessage::query()->with('customer:id,name');

        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->query('customer_id'));
        }
        if ($request->filled('template')) {
            $query->where('template', $request->query('template'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        if ($search = $request->query('search')) {
            $query->where(fn ($q) => $q->where('phone', 'like', "%{$search}%")->orWhere('body', 'like', "%{$search}%"));
        }

        $query->orderByDesc('id');
        $perPage = min((int) $request->query('per_page', 15), 100);

        return SmsMessageResource::collection($query->paginate($perPage));
    }

    /** Free-text / templated manual send to a phone or customer. */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'phone' => ['nullable', 'string', 'max:20'],
            'template' => ['nullable', 'string', 'max:50'],
            'body' => ['required', 'string', 'max:1000'],
        ]);

        $phone = $this->resolvePhone($validated['phone'] ?? null, $validated['customer_id'] ?? null);
        if (! $phone) {
            return response()->json(['message' => 'A phone number is required to send an SMS.'], 422);
        }

        $message = $this->sms->send($phone, $validated['body'], [
            'customer_id' => $validated['customer_id'] ?? null,
            'template' => $validated['template'] ?? 'manual',
            'created_by' => $request->user()?->id,
        ]);

        return $this->respond($message);
    }

    /** Invoice SMS — notifies the customer that an invoice has been generated. */
    public function invoice(Request $request, Invoice $invoice)
    {
        $invoice->loadMissing('customer:id,name,phone');
        $customer = $invoice->customer;
        if (! $customer?->phone) {
            return response()->json(['message' => 'This invoice\'s customer has no phone number.'], 422);
        }

        $body = $this->sms->render('sms_tpl_invoice', [
            'name' => $customer->name,
            'number' => $invoice->invoice_number,
            'amount' => number_format((float) $invoice->total, 2),
            'symbol' => Setting::currencySymbol(),
            'business' => $this->businessName(),
        ]);

        $message = $this->sms->send($customer->phone, $body, [
            'customer_id' => $customer->id,
            'template' => 'invoice',
            'created_by' => $request->user()?->id,
        ]);

        return $this->respond($message);
    }

    /** Dispatch SMS — notifies every consignment customer on a dispatch plan. */
    public function dispatch(Request $request, DispatchPlan $dispatchPlan)
    {
        $dispatchPlan->loadMissing('items.customer:id,name,phone');

        $recipients = $dispatchPlan->items
            ->map(fn ($item) => $item->customer)
            ->filter(fn ($c) => $c && $c->phone)
            ->unique('id');

        if ($recipients->isEmpty()) {
            return response()->json(['message' => 'No consignment on this dispatch has a customer with a phone number.'], 422);
        }

        $sent = [];
        foreach ($recipients as $customer) {
            $body = $this->sms->render('sms_tpl_dispatch', [
                'name' => $customer->name,
                'number' => $dispatchPlan->dispatch_number,
                'date' => optional($dispatchPlan->dispatch_date)->format('d-m-Y') ?? '',
                'business' => $this->businessName(),
            ]);

            $sent[] = $this->sms->send($customer->phone, $body, [
                'customer_id' => $customer->id,
                'template' => 'dispatch',
                'created_by' => $request->user()?->id,
            ]);
        }

        return response()->json([
            'message' => count($sent).' dispatch SMS sent.',
            'data' => SmsMessageResource::collection(collect($sent)->map->load('customer')),
        ]);
    }

    /** Payment reminder — sends the customer their current outstanding balance. */
    public function paymentReminder(Request $request)
    {
        $validated = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
        ]);

        $customer = Customer::query()->findOrFail($validated['customer_id']);
        if (! $customer->phone) {
            return response()->json(['message' => 'This customer has no phone number.'], 422);
        }

        $outstanding = $customer->outstandingBalance();
        if ($outstanding <= 0) {
            return response()->json(['message' => 'This customer has no outstanding balance.'], 422);
        }

        $body = $this->sms->render('sms_tpl_payment_reminder', [
            'name' => $customer->name,
            'amount' => number_format($outstanding, 2),
            'symbol' => Setting::currencySymbol(),
            'business' => $this->businessName(),
        ]);

        $message = $this->sms->send($customer->phone, $body, [
            'customer_id' => $customer->id,
            'template' => 'payment-reminder',
            'created_by' => $request->user()?->id,
        ]);

        return $this->respond($message);
    }

    /**
     * Festival greetings — bulk-sends a greeting to selected customers, or to
     * every active customer with a phone number when no ids are given.
     */
    public function festival(Request $request)
    {
        $validated = $request->validate([
            'festival' => ['required', 'string', 'max:60'],
            'customer_ids' => ['nullable', 'array'],
            'customer_ids.*' => ['integer', 'exists:customers,id'],
            'message' => ['nullable', 'string', 'max:500'],
        ]);

        $query = Customer::query()
            ->where('is_active', true)
            ->whereNotNull('phone')
            ->where('phone', '!=', '');
        if (! empty($validated['customer_ids'])) {
            $query->whereIn('id', $validated['customer_ids']);
        }
        $customers = $query->get(['id', 'name', 'phone']);

        if ($customers->isEmpty()) {
            return response()->json(['message' => 'No customers with a phone number match this selection.'], 422);
        }

        $business = $this->businessName();
        $count = 0;
        foreach ($customers as $customer) {
            $body = ! empty($validated['message'])
                ? str_replace(
                    ['{name}', '{festival}', '{business}'],
                    [$customer->name, $validated['festival'], $business],
                    $validated['message']
                )
                : $this->sms->render('sms_tpl_festival', [
                    'name' => $customer->name,
                    'festival' => $validated['festival'],
                    'business' => $business,
                ]);

            $this->sms->send($customer->phone, $body, [
                'customer_id' => $customer->id,
                'template' => 'festival',
                'created_by' => $request->user()?->id,
            ]);
            $count++;
        }

        return response()->json(['message' => "Festival greetings sent to {$count} customer(s)."]);
    }

    /** Generic staff OTP send (e.g. verify a customer's phone number). */
    public function sendOtp(Request $request)
    {
        $validated = $request->validate([
            'phone' => ['required', 'string', 'max:20'],
            'purpose' => ['nullable', 'string', 'max:40'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
        ]);

        $customer = ! empty($validated['customer_id'])
            ? Customer::query()->find($validated['customer_id'])
            : null;

        $this->sms->generateOtp(
            $validated['phone'],
            $validated['purpose'] ?? 'phone_verification',
            $customer,
        );

        return response()->json(['message' => 'OTP sent.']);
    }

    /** Verify a generic staff OTP. */
    public function verifyOtp(Request $request)
    {
        $validated = $request->validate([
            'phone' => ['required', 'string', 'max:20'],
            'purpose' => ['nullable', 'string', 'max:40'],
            'code' => ['required', 'string', 'max:8'],
        ]);

        $ok = $this->sms->verifyOtp(
            $validated['phone'],
            $validated['purpose'] ?? 'phone_verification',
            $validated['code'],
        );

        return response()->json([
            'verified' => $ok,
            'message' => $ok ? 'OTP verified.' : 'Invalid or expired OTP.',
        ], $ok ? 200 : 422);
    }

    public function destroy(SmsMessage $smsMessage)
    {
        $smsMessage->delete();

        return response()->json(['message' => 'Message deleted.']);
    }

    // -------------------------------------------------------------- helpers ---

    private function resolvePhone(?string $phone, ?int $customerId): ?string
    {
        if ($phone) {
            return $phone;
        }
        if ($customerId) {
            return Customer::query()->whereKey($customerId)->value('phone');
        }

        return null;
    }

    private function businessName(): string
    {
        $context = app(TenantContext::class);

        return $context->has()
            ? (string) (Tenant::find($context->id())?->company_name ?? '')
            : '';
    }

    private function respond(SmsMessage $message)
    {
        $resource = new SmsMessageResource($message->load('customer'));

        if ($message->status === 'failed') {
            return $resource->response()->setStatusCode(502);
        }

        return $resource->response()->setStatusCode(201);
    }
}
