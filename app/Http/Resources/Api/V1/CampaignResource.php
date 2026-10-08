<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Products\MailForge\Enums\CampaignStatus;
use App\Products\MailForge\Models\Campaign;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Campaign
 */
class CampaignResource extends JsonResource
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
            'user_id' => $this->user_id,
            'name' => $this->name,
            'subject' => $this->subject,
            'email_body' => $this->email_body,
            'email_body_text' => $this->email_body_text,
            'raw_input_email' => $this->raw_input_email,
            'email_mode' => $this->email_mode?->value ?? (string) $this->email_mode,
            'ai_provider' => $this->ai_provider?->value ?? (string) $this->ai_provider,
            'ai_prompt' => $this->ai_prompt,
            'status' => $this->status?->value ?? (string) $this->status,
            'source_type' => $this->source_type,
            'source_reference' => $this->source_reference,
            'total_recipients' => (int) $this->total_recipients,
            'sent_count' => (int) $this->sent_count,
            'failed_count' => (int) $this->failed_count,
            'is_review_required' => $this->status === CampaignStatus::REVIEW,
            'scheduled_at' => $this->scheduled_at?->toISOString(),
            'approved_at' => $this->approved_at?->toISOString(),
            'started_at' => $this->started_at?->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
