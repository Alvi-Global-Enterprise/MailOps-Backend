<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Campaign;

use App\Products\MailForge\Enums\AiProviderType;
use App\Products\MailForge\Models\Campaign;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCampaignRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $campaign = $this->route('campaign');

        return $campaign instanceof Campaign && ($this->user()?->can('update', $campaign) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'subject' => ['nullable', 'string', 'max:255'],
            'email_body' => ['nullable', 'string'],
            'email_body_text' => ['nullable', 'string'],
            'ai_prompt' => ['nullable', 'string'],
            'raw_input_email' => ['nullable', 'string'],
            'ai_provider' => ['nullable', Rule::enum(AiProviderType::class)],
            'source_type' => ['nullable', 'string', 'max:100'],
            'source_reference' => ['nullable', 'string', 'max:255'],
            'scheduled_at' => ['nullable', 'date'],
        ];
    }
}
