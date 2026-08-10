<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Mail\DocumentMail;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\PurchaseOrder;
use App\Models\SalesQuotation;
use App\Models\StockReturn;
use App\Services\DocumentContext;
use App\Services\MailService;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Emails a business document (invoice / purchase order / quotation / purchase
 * debit note) with its PDF attached. Runs in a queue worker, so it re-establishes
 * the tenant context before touching any tenant-scoped data or the tenant's SMTP
 * settings.
 *
 * The EmailLog row is the source of truth for delivery — a send failure is
 * captured there (status=failed, error=...) rather than crashing the job.
 */
class SendDocumentEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(
        public int $tenantId,
        public int $emailLogId,
        public string $documentType,   // invoice / purchase_order / quotation
        public int $documentId,
        public string $greeting,
        public string $body,
    ) {}

    public function handle(TenantContext $context, DocumentContext $docs, MailService $mail): void
    {
        $context->runWith($this->tenantId, function () use ($docs, $mail) {
            $log = EmailLog::find($this->emailLogId);
            if (! $log) {
                return;
            }

            try {
                [$view, $data, $filename] = $this->resolveDocument();

                $pdf = $docs->pdfBytes($view, $data, 'a4');

                $mailable = new DocumentMail(
                    subjectLine: $log->subject,
                    greeting: $this->greeting,
                    body: $this->body,
                    pdf: $pdf,
                    filename: $filename,
                    companyInfo: $docs->company(),
                );

                $mailer = $mail->mailer()->to($log->to_email, $log->to_name);
                if ($log->cc) {
                    $mailer->cc(array_filter(array_map('trim', explode(',', $log->cc))));
                }
                $mailer->send($mailable);

                $log->markSent();
            } catch (Throwable $e) {
                $log->markFailed($e->getMessage());
            }
        });
    }

    /**
     * Resolve the [Blade view, view data, attachment filename] for the document,
     * loading the same relations the interactive PDF endpoints use.
     *
     * @return array{0:string,1:array,2:string}
     */
    private function resolveDocument(): array
    {
        return match ($this->documentType) {
            'invoice' => (function () {
                $invoice = Invoice::with([
                    'customer', 'broker:id,name',
                    'salesOrder.items.product:id,name,sku',
                    'salesOrder.items.variant:id,name,sku',
                ])->findOrFail($this->documentId);

                return ['documents.invoice', ['invoice' => $invoice], "invoice-{$invoice->invoice_number}.pdf"];
            })(),

            'purchase_order' => (function () {
                $po = PurchaseOrder::with([
                    'supplier', 'warehouse:id,name',
                    'items.product:id,name,sku', 'items.variant:id,name,sku',
                ])->findOrFail($this->documentId);

                return ['documents.purchase-order', ['purchaseOrder' => $po], "purchase-order-{$po->po_number}.pdf"];
            })(),

            'quotation' => (function () {
                $quote = SalesQuotation::with([
                    'customer', 'warehouse:id,name',
                    'items.product:id,name,sku', 'items.variant:id,name,sku',
                ])->findOrFail($this->documentId);

                return ['documents.quotation', ['quotation' => $quote], "quotation-{$quote->quote_number}.pdf"];
            })(),

            /**
             * The purchase debit note (retail base §3). The one document here
             * whose recipient is a supplier being *asked for money* rather than
             * being sold to or bought from, which is exactly why emailing it
             * matters: a claim that sits unsent is credit the shop never collects.
             */
            'debit_note' => (function () {
                $return = StockReturn::with([
                    'supplier', 'warehouse:id,name', 'goodsReceipt:id,grn_number',
                    'items.product:id,name,sku,hsn_code', 'items.variant:id,name,sku',
                ])->findOrFail($this->documentId);

                return ['documents.debit-note', ['return' => $return], "debit-note-{$return->debit_note_number}.pdf"];
            })(),

            default => throw new \InvalidArgumentException("Unknown document type [{$this->documentType}]."),
        };
    }
}
