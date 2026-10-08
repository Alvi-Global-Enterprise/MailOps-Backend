<?php

declare(strict_types=1);

namespace App\Products\MailForge\Actions\Campaign;

use App\Products\MailForge\Enums\CampaignStatus;
use App\Products\MailForge\Enums\RecipientDeliveryStatus;
use App\Products\MailForge\Jobs\SendCampaignEmailJob;
use App\Products\MailForge\Models\Campaign;
use App\Products\MailForge\Models\CampaignRecipient;
use DomainException;
use Illuminate\Support\Facades\Log;

class ResumeCampaignAction
{
    /**
     * Resume a paused campaign and dispatch remaining queued recipients.
     */
    public function execute(Campaign $campaign): Campaign
    {
        if ($campaign->status !== CampaignStatus::PAUSED) {
            throw new DomainException("Cannot resume campaign that is not paused. Current status: [{$campaign->status->value}].");
        }

        $affected = Campaign::where('id', $campaign->id)
            ->where('status', CampaignStatus::PAUSED->value)
            ->update([
                'status' => CampaignStatus::SENDING->value,
                'updated_at' => now(),
            ]);

        if ($affected === 0) {
            throw new DomainException('Campaign resume conflict: status was modified concurrently.');
        }

        $campaign->refresh();

        $rateLimitPerMinute = max(1, (int) config(
            'mailforge.delivery.rate_limit_per_minute',
            config('mailforge.defaults.rate_limit_per_minute', 120)
        ));
        $intervalSeconds = 60.0 / $rateLimitPerMinute;
        $dispatchedCount = 0;

        // Dispatch remaining queued recipients
        CampaignRecipient::where('campaign_id', $campaign->id)
            ->where('status', RecipientDeliveryStatus::QUEUED->value)
            ->chunkById(500, function ($recipients) use (&$dispatchedCount, $intervalSeconds) {
                foreach ($recipients as $recipient) {
                    $delaySeconds = (int) floor($dispatchedCount * $intervalSeconds);

                    if ($delaySeconds > 0) {
                        SendCampaignEmailJob::dispatch($recipient->id)->delay(now()->addSeconds($delaySeconds));
                    } else {
                        SendCampaignEmailJob::dispatch($recipient->id);
                    }

                    $dispatchedCount++;
                }
            });

        Log::info('Campaign resumed successfully', [
            'campaign_id' => $campaign->id,
            'queued_recipients_dispatched' => $dispatchedCount,
        ]);

        return $campaign;
    }
}
