<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Contact;

use App\Products\MailForge\Models\Contact;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateContactRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $userId = $this->user()?->id;
        /** @var Contact|null $contact */
        $contact = $this->route('contact');
        $contactId = $contact instanceof Contact ? $contact->id : $contact;

        return [
            'email' => [
                'sometimes',
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('contacts', 'email')
                    ->where('user_id', $userId)
                    ->whereNull('deleted_at')
                    ->ignore($contactId),
            ],
            'first_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'full_name' => ['nullable', 'string', 'max:255'],
            'company' => ['nullable', 'string', 'max:255'],
            'job_title' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'external_id' => ['nullable', 'string', 'max:255'],
            'custom_data' => ['nullable', 'array'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
