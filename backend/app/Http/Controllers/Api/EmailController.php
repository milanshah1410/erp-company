<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SendDocumentEmail;
use App\Models\Customer;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\PurchaseOrder;
use App\Models\SalesQuotation;
use App\Models\Setting;
use App\Models\StockReturn;
use App\Models\Supplier;
use App\Services\MailService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Throwable;

/**
 * Email Integration (SRS §19). Sends business documents (invoice / purchase
 * order / quotation / purchase debit note) to their party with the PDF attached,
 * exposes the outbound
 * email log, and provides an SMTP connection test. Actual delivery is handled by
 * the tenant-aware SendDocumentEmail queue job; this controller only validates,
 * logs the intent, and dispatches.
 */
class EmailController extends Controller
{
    public function __construct(private readonly MailService $mail) {}

    /** Outbound email log, newest first. */
    public function index(Request $request)
    {
        $query = EmailLog::query()->with('creator:id,name');

        if ($request->filled('mailable')) {
            $query->where('mailable', $request->query('mailable'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        if ($request->filled('campaign_id')) {
            $query->where('campaign_id', $request->query('campaign_id'));
        }
        if ($search = $request->query('search')) {
            $query->where(fn ($q) => $q->where('to_email', 'like', "%{$search}%")
                ->orWhere('subject', 'like', "%{$search}%"));
        }

        $sort = $request->query('sort');
        if ($sort && in_array($sort, ['to_email', 'subject', 'mailable', 'status', 'sent_at', 'created_at'], true)) {
            $query->orderBy($sort, $request->query('direction') === 'asc' ? 'asc' : 'desc');
        } else {
            $query->orderByDesc('id');
        }
        $perPage = min((int) $request->query('per_page', 15), 100);

        $page = $query->paginate($perPage);
        $page->getCollection()->transform(fn (EmailLog $l) => $this->payload($l));

        return $page;
    }

    /** Current SMTP status for the compose/settings UI. */
    public function status()
    {
        $c = Setting::mailConfig();

        return response()->json([
            'data' => [
                'driver' => $c['mail_driver'] ?? 'log',
                'configured' => $this->mail->configured(),
                'from' => $this->mail->from(),
            ],
        ]);
    }

    public function sendInvoice(Request $request, Invoice $invoice)
    {
        $invoice->loadMissing('customer:id,name,email');

        return $this->queueDocument(
            $request,
            type: 'invoice',
            model: $invoice,
            number: $invoice->invoice_number,
            party: $invoice->customer,
            defaultSubject: 'Invoice '.$invoice->invoice_number,
            defaultBody: "Please find attached invoice {$invoice->invoice_number}. We appreciate your business.",
        );
    }

    public function sendPurchaseOrder(Request $request, PurchaseOrder $purchaseOrder)
    {
        $purchaseOrder->loadMissing('supplier:id,name,email');

        return $this->queueDocument(
            $request,
            type: 'purchase_order',
            model: $purchaseOrder,
            number: $purchaseOrder->po_number,
            party: $purchaseOrder->supplier,
            defaultSubject: 'Purchase Order '.$purchaseOrder->po_number,
            defaultBody: "Please find attached purchase order {$purchaseOrder->po_number}. Kindly confirm receipt.",
        );
    }

    public function sendQuotation(Request $request, SalesQuotation $salesQuotation)
    {
        $salesQuotation->loadMissing('customer:id,name,email');

        return $this->queueDocument(
            $request,
            type: 'quotation',
            model: $salesQuotation,
            number: $salesQuotation->quote_number,
            party: $salesQuotation->customer,
            defaultSubject: 'Quotation '.$salesQuotation->quote_number,
            defaultBody: "Please find attached our quotation {$salesQuotation->quote_number}. We look forward to your order.",
        );
    }

    /**
     * Email a raised purchase debit note to the supplier (retail base §3).
     *
     * Refuses a return with no note raised on it, rather than attaching a PDF
     * with a blank number: what lands in the vendor's inbox has to be a document
     * their accounts clerk can file against a reference.
     */
    public function sendDebitNote(Request $request, StockReturn $stockReturn)
    {
        if ($stockReturn->debit_note_issued_at === null) {
            return response()->json([
                'message' => 'Raise the debit note first — there is nothing to send yet.',
            ], 422);
        }

        $stockReturn->loadMissing('supplier:id,name,email');

        return $this->queueDocument(
            $request,
            type: 'debit_note',
            model: $stockReturn,
            number: (string) $stockReturn->debit_note_number,
            party: $stockReturn->supplier,
            defaultSubject: 'Debit Note '.$stockReturn->debit_note_number,
            defaultBody: "Please find attached our debit note {$stockReturn->debit_note_number} for goods returned to you. Kindly issue your credit note against this reference.",
        );
    }

    /** Send a test email so the operator can verify their SMTP settings. Sync. */
    public function test(Request $request)
    {
        $data = $request->validate([
            'to' => ['required', 'email'],
        ]);

        $from = $this->mail->from();

        try {
            $this->mail->mailer()->raw(
                'This is a test email from your '.config('app.name').' workspace. If you received it, your email settings are working.',
                function ($message) use ($data, $from) {
                    $message->to($data['to'])
                        ->from($from['address'], $from['name'])
                        ->subject(config('app.name').' — Test Email');
                }
            );
        } catch (Throwable $e) {
            return response()->json([
                'message' => 'Test email failed: '.$e->getMessage(),
            ], 422);
        }

        $driver = Setting::mailConfig()['mail_driver'] ?? 'log';

        return response()->json([
            'message' => $driver === 'smtp'
                ? "Test email sent to {$data['to']}."
                : "Test email written to the application log (mail driver is 'log'). Configure SMTP to send real email.",
        ]);
    }

    /**
     * Validate a document-send request, log the intent, and dispatch the job.
     *
     * @param  Customer|Supplier|null  $party
     */
    private function queueDocument(Request $request, string $type, $model, string $number, $party, string $defaultSubject, string $defaultBody)
    {
        $validated = $request->validate([
            'to' => ['nullable', 'email'],
            'cc' => ['nullable', 'string', 'max:500'],
            'subject' => ['nullable', 'string', 'max:200'],
            'message' => ['nullable', 'string', 'max:5000'],
        ]);

        $to = $validated['to'] ?? $party?->email;
        if (! $to) {
            return response()->json([
                'message' => 'No recipient email. Add an email to the record or enter one to send to.',
            ], 422);
        }

        $subject = $validated['subject'] ?? $defaultSubject;
        $body = $validated['message'] ?? $defaultBody;
        $greeting = 'Dear '.($party?->name ?? 'Sir/Madam').',';

        $log = EmailLog::create([
            'mailable' => $type,
            'related_type' => $model::class,
            'related_id' => $model->id,
            'to_email' => $to,
            'to_name' => $party?->name,
            'subject' => $subject,
            'cc' => $validated['cc'] ?? null,
            'status' => 'queued',
            'created_by' => $request->user()?->id,
        ]);

        SendDocumentEmail::dispatch(
            tenantId: app(TenantContext::class)->id(),
            emailLogId: $log->id,
            documentType: $type,
            documentId: $model->id,
            greeting: $greeting,
            body: $body,
        );

        return response()->json([
            'message' => "Email queued to {$to}.",
            'data' => $this->payload($log),
        ], 202);
    }

    private function payload(EmailLog $l): array
    {
        return [
            'id' => $l->id,
            'mailable' => $l->mailable,
            'to_email' => $l->to_email,
            'to_name' => $l->to_name,
            'subject' => $l->subject,
            'cc' => $l->cc,
            'status' => $l->status,
            'error' => $l->error,
            'campaign_id' => $l->campaign_id,
            'sent_at' => $l->sent_at,
            'by' => $l->creator?->name,
            'created_at' => $l->created_at,
        ];
    }
}
