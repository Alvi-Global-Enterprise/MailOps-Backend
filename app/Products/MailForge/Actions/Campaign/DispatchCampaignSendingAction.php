<?php

declare(strict_types=1);

namespace App\Products\MailForge\Actions\Campaign;

use App\Products\MailForge\Enums\CampaignStatus;
use App\Products\MailForge\Enums\RecipientDeliveryStatus;
use App\Products\MailForge\Events\CampaignDispatched;
use App\Products\MailForge\Jobs\SendCampaignEmailJob;
use App\Products\MailForge\Models\Campaign;
use App\Products\MailForge\Models\CampaignRecipient;
use DomainException;
use Illuminate\Support\Facades\Log;

class DispatchCampaignSendingAction
{
    /**
     * Dispatch an approved campaign for queue delivery.
     *
     * @return int Number of recipients enqueued
     */
    public function execute(Campaign $campaign): int
    {
        if ($campaign->status !== CampaignStatus::APPROVED) {
            throw new DomainException("Only approved campaigns can be dispatched. Current status: [{$campaign->status->value}].");
        }

        $recipientCount = CampaignRecipient::where('campaign_id', $campaign->id)->count();
        if ($recipientCount === 0) {
            throw new DomainException('Cannot dispatch campaign: no recipients attached.');
        }

        // Atomic transition from APPROVED to SENDING
        $affected = Campaign::where('id', $campaign->id)
            ->where('status', CampaignStatus::APPROVED->value)
            ->update([
                'status' => CampaignStatus::SENDING->value,
                'started_at' => now(),
            ]);

        if ($affected === 0) {
            throw new DomainException('Campaign dispatch conflict: status was modified concurrently.');
        }

        $campaign->refresh();

        $rateLimitPerMinute = max(1, (int) config('mailforge.delivery.rate_limit_per_minute',
            config('mailforge.defaults.rate_limit_per_minute', 120)));
        $intervalSeconds = 60.0 / $rateLimitPerMinute;

        $dispatchedCount = 0;

        // Process recipients in chunks of 500
        CampaignRecipient::where('campaign_id', $campaign->id)
            ->whereIn('status', [RecipientDeliveryStatus::DRAFT->value, RecipientDeliveryStatus::QUEUED->value])
            ->chunkById(500, function ($recipients) use (&$dispatchedCount, $intervalSeconds) {
                $recipientIds = $recipients->pluck('id')->all();

                CampaignRecipient::whereIn('id', $recipientIds)
                    ->update([
                        'status' => RecipientDeliveryStatus::QUEUED->value,
                        'queued_at' => now(),
                    ]);

                foreach ($recipientIds as $recipientId) {
                    $delaySeconds = (int) floor($dispatchedCount * $intervalSeconds);

                    if ($delaySeconds > 0) {
                        SendCampaignEmailJob::dispatch($recipientId)->delay(now()->addSeconds($delaySeconds));
                    } else {
                        SendCampaignEmailJob::dispatch($recipientId);
                    }

                    $dispatchedCount++;
                }
            });

        CampaignDispatched::dispatch($campaign, $dispatchedCount);

        Log::info('Campaign dispatched successfully', [
            'campaign_id' => $campaign->id,
            'enqueued_recipients' => $dispatchedCount,
            'rate_limit_per_minute' => $rateLimitPerMinute,
        ]);

        return $dispatchedCount;
    }
}
