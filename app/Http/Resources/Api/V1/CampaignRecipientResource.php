<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Products\MailForge\Models\CampaignRecipient;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CampaignRecipient
 */
class CampaignRecipientResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'campaign_id' => $this->campaign_id,
            'contact_id' => $this->contact_id,
            'email' => $this->email,
            'subject' => $this->subject,
            'status' => $this->status?->value ?? (string) $this->status,
            'attempts' => (int) $this->attempts,
            'last_error' => $this->error_message,
            'custom_attributes' => is_array($this->custom_attributes) ? $this->custom_attributes : [],
            'queued_at' => $this->queued_at?->toISOString(),
            'processing_started_at' => $this->processing_started_at?->toISOString(),
            'sent_at' => $this->sent_at?->toISOString(),
            'failed_at' => $this->failed_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
