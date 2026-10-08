<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Campaign;

use App\Products\MailForge\Enums\AiProviderType;
use App\Products\MailForge\Enums\EmailMode;
use App\Products\MailForge\Models\Campaign;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreCampaignRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', Campaign::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email_mode' => ['required', Rule::enum(EmailMode::class)],
            'ai_provider' => ['nullable', Rule::enum(AiProviderType::class)],
            'source_type' => ['nullable', 'string', 'max:100'],
            'source_reference' => ['nullable', 'string', 'max:255'],
            'scheduled_at' => ['nullable', 'date'],
            'subject' => ['nullable', 'string', 'max:255'],
            'email_body' => ['nullable', 'string'],
            'email_body_text' => ['nullable', 'string'],
            'ai_prompt' => ['nullable', 'string'],
            'raw_input_email' => ['nullable', 'string'],
        ];
    }

    /**
     * Configure additional validator checks for mode-specific requirements.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $modeInput = $this->input('email_mode');
            if (! $modeInput) {
                return;
            }

            $mode = $modeInput instanceof EmailMode
                ? $modeInput
                : EmailMode::tryFrom((string) $modeInput);

            if (! $mode) {
                return;
            }

            if ($mode === EmailMode::DIRECT) {
                if (empty(trim((string) $this->input('subject')))) {
                    $validator->errors()->add('subject', 'Direct email mode requires a subject line.');
                }
                if (empty(trim((string) $this->input('email_body')))) {
                    $validator->errors()->add('email_body', 'Direct email mode requires an email body.');
                }
            } elseif ($mode === EmailMode::GENERATE) {
                if (empty(trim((string) $this->input('ai_prompt')))) {
                    $validator->errors()->add('ai_prompt', 'Generate mode requires an AI prompt instruction.');
                }
            } elseif ($mode === EmailMode::POLISH) {
                $raw = trim((string) $this->input('raw_input_email'));
                $body = trim((string) $this->input('email_body'));
                if (empty($raw) && empty($body)) {
                    $validator->errors()->add('raw_input_email', 'Polish mode requires rough draft email content to polish.');
                }
            }
        });
    }
}
