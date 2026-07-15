<?php

declare(strict_types=1);

namespace App\Mail;

use App\Services\MailService;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * A marketing-campaign email: the operator-authored HTML body wrapped in a
 * minimal branded shell. One instance is sent per recipient by the send job.
 */
class MarketingCampaignMail extends Mailable
{
    public function __construct(
        public string $subjectLine,
        public string $htmlBody,
        public ?string $recipientName = null,
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
            view: 'emails.campaign',
            with: [
                'htmlBody' => $this->htmlBody,
                'recipientName' => $this->recipientName,
                'companyInfo' => $this->companyInfo,
            ],
        );
    }
}
