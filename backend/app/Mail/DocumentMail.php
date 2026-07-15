<?php

declare(strict_types=1);

namespace App\Mail;

use App\Services\MailService;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * A business-document email (invoice / purchase order / quotation) with the
 * rendered PDF attached. Built and sent inside the tenant-aware send job, so the
 * From address resolves from that tenant's mail settings.
 */
class DocumentMail extends Mailable
{
    /**
     * @param  string  $subjectLine  Email subject
     * @param  string  $greeting  e.g. "Dear Acme Traders,"
     * @param  string  $body  Operator-authored message body (plain text)
     * @param  string|null  $pdf  Raw PDF bytes to attach (null = no attachment)
     * @param  string  $filename  Attachment filename
     * @param  array  $companyInfo  Sender company details for the signature
     */
    public function __construct(
        public string $subjectLine,
        public string $greeting,
        public string $body,
        public ?string $pdf,
        public string $filename,
        public array $companyInfo = [],
    ) {}

    public function envelope(): Envelope
    {
        $from = app(MailService::class)->from();

        return new Envelope(
            from: new Address($from['address'], $from['name']),
            subject: $this->subjectLine,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.document',
            with: [
                'greeting' => $this->greeting,
                'body' => $this->body,
                'companyInfo' => $this->companyInfo,
            ],
        );
    }

    public function attachments(): array
    {
        if ($this->pdf === null) {
            return [];
        }

        return [
            Attachment::fromData(fn () => $this->pdf, $this->filename)
                ->withMime('application/pdf'),
        ];
    }
}
