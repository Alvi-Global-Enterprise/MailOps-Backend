<?php

declare(strict_types=1);

namespace App\Products\MailForge\Actions\Campaign;

use App\Products\MailForge\Enums\CampaignStatus;
use App\Products\MailForge\Enums\RecipientDeliveryStatus;
use App\Products\MailForge\Models\Campaign;
use App\Products\MailForge\Models\CampaignRecipient;
use DomainException;

class CancelCampaignAction
{
    /**
     * Cancel an active or pending campaign.
     */
    public function execute(Campaign $campaign): Campaign
    {
        if ($campaign->status->isTerminal()) {
            throw new DomainException("Cannot cancel campaign that has already reached terminal status [{$campaign->status->value}].");
        }

        $campaign->update([
            'status' => CampaignStatus::CANCELLED,
            'completed_at' => now(),
        ]);

        // Cancel all pending / queued recipients
        CampaignRecipient::where('campaign_id', $campaign->id)
            ->whereIn('status', [
                RecipientDeliveryStatus::DRAFT->value,
                RecipientDeliveryStatus::QUEUED->value,
            ])
            ->update([
                'status' => RecipientDeliveryStatus::CANCELLED->value,
            ]);

        return $campaign->fresh();
    }
}
