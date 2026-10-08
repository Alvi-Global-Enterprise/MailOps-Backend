<?php

declare(strict_types=1);

namespace App\Products\MailForge\Actions\Campaign;

use App\Products\MailForge\Enums\CampaignStatus;
use App\Products\MailForge\Models\Campaign;
use DomainException;

class PauseCampaignAction
{
    /**
     * Pause an in-flight campaign.
     */
    public function execute(Campaign $campaign): Campaign
    {
        if (! in_array($campaign->status, [CampaignStatus::SENDING, CampaignStatus::QUEUED], true)) {
            throw new DomainException("Cannot pause campaign in [{$campaign->status->value}] status.");
        }

        $campaign->update([
            'status' => CampaignStatus::PAUSED,
        ]);

        return $campaign->fresh();
    }
}
