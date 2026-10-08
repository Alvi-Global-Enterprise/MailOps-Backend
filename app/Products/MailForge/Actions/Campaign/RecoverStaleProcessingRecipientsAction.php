<?php

declare(strict_types=1);

namespace App\Products\MailForge\Actions\Campaign;

use App\Products\MailForge\Enums\CampaignStatus;
use App\Products\MailForge\Enums\RecipientDeliveryStatus;
use App\Products\MailForge\Jobs\SendCampaignEmailJob;
use App\Products\MailForge\Models\Campaign;
use App\Products\MailForge\Models\CampaignRecipient;
use App\Products\MailForge\Models\EmailDeliveryLog;
use Illuminate\Support\Facades\Log;

class RecoverStaleProcessingRecipientsAction
{
    /**
     * Scan and recover any recipients stuck in processing due to worker crashes.
     *
     * @return array{recovered: int, failed: int}
     */
    public function execute(?int $leaseSeconds = null): array
    {
        $lease = $leaseSeconds ?: (int) config('mailforge.delivery.processing_lease_seconds', 300);
        $staleCutoff = now()->subSeconds($lease);
        $maxTries = (int) config('mailforge.delivery.max_retry_attempts', 3);

        $staleRecipients = CampaignRecipient::with('campaign')
            ->where('status', RecipientDeliveryStatus::PROCESSING->value)
            ->where('processing_started_at', '<=', $staleCutoff)
            ->get();

        $recoveredCount = 0;
        $failedCount = 0;

        foreach ($staleRecipients as $recipient) {
            $campaign = $recipient->campaign;

            if ($campaign && $campaign->status === CampaignStatus::CANCELLED) {
                $recipient->update([
                    'status' => RecipientDeliveryStatus::CANCELLED,
                    'processing_started_at' => null,
                ]);

                continue;
            }

            if ($recipient->attempts >= $maxTries) {
                // Permanently fail abandoned records that exceeded max tries
                $recipient->update([
                    'status' => RecipientDeliveryStatus::FAILED,
                    'failed_at' => now(),
                    'error_message' => 'Abandoned processing lease; worker crash recovery exceeded max attempts.',
                ]);

                if ($campaign) {
                    EmailDeliveryLog::create([
                        'campaign_recipient_id' => $recipient->id,
                        'campaign_id' => $campaign->id,
                        'provider' => 'system-recovery',
                        'status' => 'failed',
                        'response_code' => '504',
                        'provider_response' => 'Worker crash lease expired',
                        'attempt' => $recipient->attempts,
                    ]);

                    Campaign::where('id', $campaign->id)->increment('failed_count');
                }

                $failedCount++;
            } else {
                // Return to QUEUED and re-dispatch if campaign is active
                $recipient->update([
                    'status' => RecipientDeliveryStatus::QUEUED,
                    'processing_started_at' => null,
                ]);

                if ($campaign && $campaign->status === CampaignStatus::SENDING) {
                    SendCampaignEmailJob::dispatch($recipient->id);
                }

                $recoveredCount++;
            }

            if ($campaign) {
                $this->checkCampaignCompletion($campaign);
            }
        }

        if ($recoveredCount > 0 || $failedCount > 0) {
            Log::warning('Recovered stale processing recipients from crashed workers', [
                'recovered' => $recoveredCount,
                'permanently_failed' => $failedCount,
                'lease_seconds' => $lease,
            ]);
        }

        return [
            'recovered' => $recoveredCount,
            'failed' => $failedCount,
        ];
    }

    /**
     * Check if campaign should transition to completed.
     */
    protected function checkCampaignCompletion(Campaign $campaign): void
    {
        $pendingCount = CampaignRecipient::where('campaign_id', $campaign->id)
            ->whereNotIn('status', [
                RecipientDeliveryStatus::SENT->value,
                RecipientDeliveryStatus::FAILED->value,
                RecipientDeliveryStatus::CANCELLED->value,
            ])
            ->count();

        if ($pendingCount === 0) {
            Campaign::where('id', $campaign->id)
                ->whereIn('status', [CampaignStatus::SENDING->value, CampaignStatus::QUEUED->value])
                ->update([
                    'status' => CampaignStatus::COMPLETED->value,
                    'completed_at' => now(),
                ]);
        }
    }
}
