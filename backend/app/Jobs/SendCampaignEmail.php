<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Mail\MarketingCampaignMail;
use App\Models\EmailLog;
use App\Models\MarketingCampaign;
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
 * Sends a marketing campaign to its resolved audience, one email per recipient,
 * logging each send to email_logs and rolling up the counts onto the campaign.
 * Runs in a queue worker and re-establishes the tenant context first.
 */
class SendCampaignEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        public int $tenantId,
        public int $campaignId,
    ) {}

    public function handle(TenantContext $context, DocumentContext $docs, MailService $mail): void
    {
        $context->runWith($this->tenantId, function () use ($docs, $mail) {
            $campaign = MarketingCampaign::find($this->campaignId);
            if (! $campaign) {
                return;
            }

            $recipients = $campaign->resolveRecipients();
            $company = $docs->company();

            $sent = 0;
            $failed = 0;

            $campaign->update([
                'status' => 'sending',
                'recipient_count' => count($recipients),
                'sent_count' => 0,
                'failed_count' => 0,
            ]);

            foreach ($recipients as $recipient) {
                $log = EmailLog::create([
                    'mailable' => 'campaign',
                    'campaign_id' => $campaign->id,
                    'to_email' => $recipient['email'],
                    'to_name' => $recipient['name'] ?? null,
                    'subject' => $campaign->subject,
                    'status' => 'queued',
                    'created_by' => $campaign->created_by,
                ]);

                try {
                    $mailable = new MarketingCampaignMail(
                        subjectLine: $campaign->subject,
                        htmlBody: $campaign->body,
                        recipientName: $recipient['name'] ?? null,
                        companyInfo: $company,
                    );

                    $mail->mailer()->to($recipient['email'], $recipient['name'] ?? null)->send($mailable);

                    $log->markSent();
                    $sent++;
                } catch (Throwable $e) {
                    $log->markFailed($e->getMessage());
                    $failed++;
                }
            }

            $campaign->update([
                'status' => $failed > 0 && $sent === 0 ? 'failed' : 'sent',
                'sent_count' => $sent,
                'failed_count' => $failed,
                'sent_at' => now(),
            ]);
        });
    }
}
