<?php

declare(strict_types=1);

namespace App\Products\MailForge\Actions\Campaign;

use App\Products\MailForge\Enums\RecipientDeliveryStatus;
use App\Products\MailForge\Models\Campaign;
use App\Products\MailForge\Models\CampaignRecipient;
use App\Products\MailForge\Models\Contact;

class CreateCampaignRecipientsAction
{
    /**
     * Attach contacts as recipients to a campaign in memory-efficient chunks.
     *
     * @param  list<int>|null  $contactIds  Explicit contact IDs, or null to target all matching contacts
     * @return int Total recipients now attached to the campaign
     */
    public function execute(Campaign $campaign, ?array $contactIds = null): int
    {
        $contactQuery = Contact::where('user_id', $campaign->user_id)
            ->where('is_active', true);

        if (! empty($contactIds)) {
            $contactQuery->whereIn('id', $contactIds);
        } elseif ($campaign->source_type === 'google_sheet' && ! empty($campaign->source_reference)) {
            $contactQuery->where('source_reference', $campaign->source_reference);
        }

        // Exclude contacts already attached to this campaign to prevent duplicate recipient errors
        $existingContactIds = CampaignRecipient::where('campaign_id', $campaign->id)
            ->pluck('contact_id')
            ->all();

        if (! empty($existingContactIds)) {
            $contactQuery->whereNotIn('id', $existingContactIds);
        }

        $now = now();
        $fallbackSubject = $campaign->subject ?: 'Campaign Outreach';
        $fallbackBody = $campaign->email_body ?: '';

        // Process in chunks of 500
        $contactQuery->chunkById(500, function ($contacts) use ($campaign, $fallbackSubject, $fallbackBody, $now) {
            $recipientRecords = [];

            foreach ($contacts as $contact) {
                $recipientRecords[] = [
                    'campaign_id' => $campaign->id,
                    'contact_id' => $contact->id,
                    'email' => $contact->email,
                    'subject' => $fallbackSubject,
                    'personalized_body' => $fallbackBody,
                    'status' => RecipientDeliveryStatus::DRAFT->value,
                    'attempts' => 0,
                    'custom_attributes' => json_encode([]),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if (! empty($recipientRecords)) {
                CampaignRecipient::insert($recipientRecords);
            }
        });

        // Update campaign total recipients counter
        $totalRecipients = CampaignRecipient::where('campaign_id', $campaign->id)->count();

        $campaign->update([
            'total_recipients' => $totalRecipients,
        ]);

        return $totalRecipients;
    }
}
