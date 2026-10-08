<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Products\MailForge\Models\Contact;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Contact
 */
class ContactResource extends JsonResource
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
            'email' => $this->email,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => $this->full_name,
            'company' => $this->company,
            'job_title' => $this->job_title,
            'phone' => $this->phone,
            'external_id' => $this->external_id,
            'source' => $this->source?->value ?? (string) $this->source,
            'source_reference' => $this->source_reference,
            'custom_data' => is_array($this->custom_data) ? $this->custom_data : [],
            'is_active' => (bool) $this->is_active,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
