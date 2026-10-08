<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Campaign;

use App\Products\MailForge\Models\Campaign;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AddCampaignRecipientsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $campaign = $this->route('campaign');

        return $campaign instanceof Campaign && ($this->user()?->can('manageRecipients', $campaign) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'contact_ids' => ['required', 'array', 'min:1'],
            'contact_ids.*' => ['required', 'integer', 'distinct', 'exists:contacts,id'],
        ];
    }
}
