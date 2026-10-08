<?php

declare(strict_types=1);

namespace App\Products\MailForge\Actions\Campaign;

use App\Products\MailForge\Enums\CampaignStatus;
use App\Products\MailForge\Events\CampaignApproved;
use App\Products\MailForge\Models\Campaign;
use DomainException;
use InvalidArgumentException;

class ApproveCampaignAction
{
    /**
     * Approve a campaign for dispatching.
     */
    public function execute(Campaign $campaign): Campaign
    {
        if (empty($campaign->subject)) {
            throw new InvalidArgumentException('Cannot approve campaign without a subject line.');
        }

        if (empty($campaign->email_body)) {
            throw new InvalidArgumentException('Cannot approve campaign without an email body.');
        }

        if (! $campaign->status->canApprove()) {
            throw new DomainException("Campaign cannot be approved from current status [{$campaign->status->value}].");
        }

        $affected = Campaign::where('id', $campaign->id)
            ->whereIn('status', [CampaignStatus::DRAFT->value, CampaignStatus::REVIEW->value])
            ->update([
                'status' => CampaignStatus::APPROVED->value,
                'approved_at' => now(),
            ]);

        if ($affected === 0) {
            throw new DomainException('Concurrent modification prevented campaign approval.');
        }

        $freshCampaign = $campaign->fresh();
        CampaignApproved::dispatch($freshCampaign);

        return $freshCampaign;
    }
}
