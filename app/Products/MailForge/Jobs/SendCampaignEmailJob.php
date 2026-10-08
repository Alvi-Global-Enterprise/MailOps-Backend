<?php

declare(strict_types=1);

namespace App\Products\MailForge\Jobs;

use App\Products\MailForge\DTOs\DeliveryResult;
use App\Products\MailForge\DTOs\EmailMessagePayload;
use App\Products\MailForge\Enums\CampaignStatus;
use App\Products\MailForge\Enums\RecipientDeliveryStatus;
use App\Products\MailForge\Events\CampaignCompleted;
use App\Products\MailForge\Events\EmailDeliveryFailed;
use App\Products\MailForge\Events\EmailSent;
use App\Products\MailForge\Models\Campaign;
use App\Products\MailForge\Models\CampaignRecipient;
use App\Products\MailForge\Models\EmailDeliveryLog;
use App\Products\MailForge\Services\Delivery\EmailDeliveryManager;
use App\Products\MailForge\Support\DeliveryErrorClassifier;
use App\Products\MailForge\Support\EmailTemplatePersonalizer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendCampaignEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    /**
     * @var list<int>
     */
    public array $backoff = [30, 120, 600];

    public function __construct(
        public readonly int $recipientId
    ) {
        $this->onQueue('campaign-sending');
    }

    /**
     * Execute the email transmission.
     */
    public function handle(EmailDeliveryManager $deliveryManager): void
    {
        $leaseSeconds = (int) config('mailforge.delivery.processing_lease_seconds', 300);
        $staleCutoff = now()->subSeconds($leaseSeconds);
        $maxTries = $this->tries;

        // 1. Strict atomic status check with lease semantics to guarantee absolute idempotency
        $affected = CampaignRecipient::where('id', $this->recipientId)
            ->where(function ($query) use ($staleCutoff, $maxTries) {
                $query->where('status', RecipientDeliveryStatus::QUEUED->value)
                    ->orWhere(function ($sub) use ($staleCutoff, $maxTries) {
                        $sub->where('status', RecipientDeliveryStatus::PROCESSING->value)
                            ->where('processing_started_at', '<=', $staleCutoff)
                            ->where('attempts', '<', $maxTries);
                    });
            })
            ->update([
                'status' => RecipientDeliveryStatus::PROCESSING->value,
                'processing_started_at' => now(),
                'attempts' => DB::raw('attempts + 1'),
                'updated_at' => now(),
            ]);

        if ($affected === 0) {
            // Already processing, completed, or cancelled by another worker
            Log::info('SendCampaignEmailJob skipped: recipient already processed or lock unavailable', [
                'recipient_id' => $this->recipientId,
            ]);

            return;
        }

        /** @var CampaignRecipient|null $recipient */
        $recipient = CampaignRecipient::with(['campaign', 'contact'])->find($this->recipientId);
        if (! $recipient || ! $recipient->campaign) {
            Log::warning('SendCampaignEmailJob aborted: recipient or parent campaign not found', [
                'recipient_id' => $this->recipientId,
            ]);

            return;
        }

        $campaign = $recipient->campaign;

        // 2. Check if campaign was paused or cancelled mid-flight
        if ($campaign->status === CampaignStatus::PAUSED) {
            $recipient->update([
                'status' => RecipientDeliveryStatus::QUEUED,
                'processing_started_at' => null,
            ]);

            return;
        }

        if ($campaign->status === CampaignStatus::CANCELLED) {
            $recipient->update([
                'status' => RecipientDeliveryStatus::CANCELLED,
                'processing_started_at' => null,
            ]);
            $this->checkCampaignCompletion($campaign);

            return;
        }

        // 3. Fast permanent failure validation on recipient email syntax
        if (empty($recipient->email) || filter_var($recipient->email, FILTER_VALIDATE_EMAIL) === false) {
            $invalidEmailResult = DeliveryResult::failed(
                provider: 'local-validator',
                errorMessage: "Invalid recipient email address: [{$recipient->email}]",
                responseCode: '501'
            );

            $this->handleFailure($recipient, $campaign, $invalidEmailResult);
            $this->checkCampaignCompletion($campaign);

            return;
        }

        $contact = $recipient->contact;

        // 4. Render personalized templates
        $subject = EmailTemplatePersonalizer::personalize(
            $recipient->subject ?: (string) $campaign->subject,
            $contact
        );

        $bodyHtml = EmailTemplatePersonalizer::personalize(
            $recipient->personalized_body ?: (string) $campaign->email_body,
            $contact
        );

        $bodyText = ! empty($campaign->email_body_text)
            ? EmailTemplatePersonalizer::personalize($campaign->email_body_text, $contact)
            : strip_tags($bodyHtml);

        $messagePayload = new EmailMessagePayload(
            to: $recipient->email,
            subject: $subject,
            bodyHtml: $bodyHtml,
            bodyText: $bodyText,
            headers: [
                'X-Campaign-ID' => (string) $campaign->id,
                'X-Recipient-ID' => (string) $recipient->id,
            ]
        );

        // 5. Re-check campaign status immediately before external API call
        $currentCampaignStatus = Campaign::where('id', $campaign->id)->value('status');
        if ($currentCampaignStatus === CampaignStatus::PAUSED->value) {
            $recipient->update([
                'status' => RecipientDeliveryStatus::QUEUED,
                'processing_started_at' => null,
            ]);

            return;
        }

        if ($currentCampaignStatus === CampaignStatus::CANCELLED->value) {
            $recipient->update([
                'status' => RecipientDeliveryStatus::CANCELLED,
                'processing_started_at' => null,
            ]);
            $this->checkCampaignCompletion($campaign);

            return;
        }

        // 6. Send email through delivery manager (outside any database transaction)
        try {
            $result = $deliveryManager->send($messagePayload);
        } catch (Throwable $e) {
            $result = DeliveryResult::failed(
                provider: 'delivery-manager',
                errorMessage: $e->getMessage(),
                responseCode: (string) ($e->getCode() ?: '500')
            );
        }

        // 7. Evaluate result
        if ($result->success) {
            $this->handleSuccess($recipient, $campaign, $result);
        } else {
            $this->handleFailure($recipient, $campaign, $result);
        }

        // 8. Check campaign completion
        $this->checkCampaignCompletion($campaign);
    }

    /**
     * Handle successful delivery.
     */
    protected function handleSuccess(CampaignRecipient $recipient, Campaign $campaign, DeliveryResult $result): void
    {
        $recipient->update([
            'status' => RecipientDeliveryStatus::SENT,
            'sent_at' => now(),
            'processing_started_at' => null,
            'provider_message_id' => $result->messageId,
            'error_message' => null,
        ]);

        EmailDeliveryLog::create([
            'campaign_recipient_id' => $recipient->id,
            'campaign_id' => $campaign->id,
            'provider' => $result->provider,
            'status' => 'sent',
            'response_code' => $result->responseCode,
            'provider_response' => $result->rawResponse,
            'attempt' => $recipient->attempts,
        ]);

        Campaign::where('id', $campaign->id)->increment('sent_count');

        EmailSent::dispatch($recipient, $result);
    }

    /**
     * Handle delivery failure.
     */
    protected function handleFailure(CampaignRecipient $recipient, Campaign $campaign, DeliveryResult $result): void
    {
        $currentAttempts = (int) $recipient->attempts;
        $isPermanent = DeliveryErrorClassifier::isPermanent($result, $currentAttempts, $this->tries);

        EmailDeliveryLog::create([
            'campaign_recipient_id' => $recipient->id,
            'campaign_id' => $campaign->id,
            'provider' => $result->provider,
            'status' => 'failed',
            'response_code' => $result->responseCode,
            'provider_response' => $result->rawResponse,
            'attempt' => $currentAttempts,
        ]);

        if (! $isPermanent) {
            // Transient error: Reset recipient status to QUEUED so subsequent queue retry can claim it
            $recipient->update([
                'status' => RecipientDeliveryStatus::QUEUED,
                'processing_started_at' => null,
                'error_message' => "Attempt {$currentAttempts} failed: {$result->errorMessage}",
            ]);

            throw new \RuntimeException("Transient email delivery failure: {$result->errorMessage}");
        }

        // Permanent failure: transition directly to FAILED and do not retry
        $recipient->update([
            'status' => RecipientDeliveryStatus::FAILED,
            'failed_at' => now(),
            'processing_started_at' => null,
            'error_message' => $result->errorMessage,
        ]);

        Campaign::where('id', $campaign->id)->increment('failed_count');

        EmailDeliveryFailed::dispatch($recipient, $result, true);
    }

    /**
     * Handle permanent job failure when max tries are exhausted by Laravel.
     */
    public function failed(?Throwable $exception): void
    {
        $recipient = CampaignRecipient::find($this->recipientId);
        if ($recipient && ! $recipient->status->isTerminal()) {
            $recipient->update([
                'status' => RecipientDeliveryStatus::FAILED,
                'failed_at' => now(),
                'processing_started_at' => null,
                'error_message' => $exception?->getMessage() ?: 'Max retries exhausted.',
            ]);

            Campaign::where('id', $recipient->campaign_id)->increment('failed_count');

            if ($recipient->campaign) {
                $this->checkCampaignCompletion($recipient->campaign);
            }
        }
    }

    /**
     * Verify whether all recipients in the campaign are in terminal states.
     */
    public function checkCampaignCompletion(Campaign $campaign): void
    {
        $totalRecipients = CampaignRecipient::where('campaign_id', $campaign->id)->count();
        if ($totalRecipients === 0) {
            return;
        }

        $pendingCount = CampaignRecipient::where('campaign_id', $campaign->id)
            ->whereNotIn('status', [
                RecipientDeliveryStatus::SENT->value,
                RecipientDeliveryStatus::FAILED->value,
                RecipientDeliveryStatus::CANCELLED->value,
            ])
            ->count();

        if ($pendingCount === 0) {
            $affected = Campaign::where('id', $campaign->id)
                ->whereIn('status', [CampaignStatus::SENDING->value, CampaignStatus::QUEUED->value])
                ->update([
                    'status' => CampaignStatus::COMPLETED->value,
                    'completed_at' => now(),
                ]);

            if ($affected > 0) {
                CampaignCompleted::dispatch($campaign->fresh());
            }
        }
    }
}
