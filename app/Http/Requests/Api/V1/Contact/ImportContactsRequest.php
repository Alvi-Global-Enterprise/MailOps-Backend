<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Contact;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ImportContactsRequest extends FormRequest
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
        return [
            'file' => [
                'required',
                'file',
                'mimes:xlsx,xls,csv,txt',
                'max:10240', // Max 10MB
            ],
        ];
    }

    /**
     * Custom validation messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'An Excel or CSV spreadsheet file is required for contact import.',
            'file.file' => 'The uploaded file must be a valid file.',
            'file.uploaded' => 'The file failed to upload. Please ensure a valid file is selected and that the file size does not exceed the server upload limit.',
            'file.mimes' => 'The file must be a spreadsheet of type: xlsx, xls, or csv.',
            'file.max' => 'The spreadsheet file must not exceed 10MB.',
        ];
    }
}
