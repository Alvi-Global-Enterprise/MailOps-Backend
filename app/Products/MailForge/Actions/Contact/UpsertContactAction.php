<?php

declare(strict_types=1);

namespace App\Products\MailForge\Actions\Contact;

use App\Products\MailForge\DTOs\SheetRowData;
use App\Products\MailForge\Enums\ContactSourceType;
use App\Products\MailForge\Models\Contact;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class UpsertContactAction
{
    /**
     * Upsert a contact record for a given user from SheetRowData or attributes.
     *
     * @param  SheetRowData|array<string, mixed>  $data
     * @return array{contact: Contact, created: bool}
     */
    public function execute(
        int $userId,
        SheetRowData|array $data,
        ContactSourceType $source = ContactSourceType::MANUAL,
        ?string $sourceReference = null
    ): array {
        if ($data instanceof SheetRowData) {
            $email = strtolower(trim($data->email));
            $firstName = $data->firstName;
            $lastName = $data->lastName;
            $fullName = $data->fullName;
            $company = $data->company;
            $jobTitle = $data->jobTitle;
            $phone = $data->phone;
            $externalId = $data->externalId;
            $customData = $data->customData;
        } else {
            $email = strtolower(trim((string) ($data['email'] ?? '')));
            $firstName = $data['first_name'] ?? null;
            $lastName = $data['last_name'] ?? null;
            $fullName = $data['full_name'] ?? null;
            $company = $data['company'] ?? null;
            $jobTitle = $data['job_title'] ?? null;
            $phone = $data['phone'] ?? null;
            $externalId = $data['external_id'] ?? null;
            $customData = (array) ($data['custom_data'] ?? []);
        }

        if (empty($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException("Cannot upsert contact with invalid email address: [{$email}]");
        }

        return DB::transaction(function () use (
            $userId,
            $email,
            $firstName,
            $lastName,
            $fullName,
            $company,
            $jobTitle,
            $phone,
            $externalId,
            $customData,
            $source,
            $sourceReference
        ) {
            $contact = Contact::where('user_id', $userId)
                ->where('email', $email)
                ->first();

            if ($contact) {
                // Merge custom data preserving existing keys if not overridden
                $existingCustomData = is_array($contact->custom_data) ? $contact->custom_data : [];
                $mergedCustomData = array_merge($existingCustomData, $customData);

                $contact->update(array_filter([
                    'first_name' => $firstName ?: $contact->first_name,
                    'last_name' => $lastName ?: $contact->last_name,
                    'full_name' => $fullName ?: $contact->full_name,
                    'company' => $company ?: $contact->company,
                    'job_title' => $jobTitle ?: $contact->job_title,
                    'phone' => $phone ?: $contact->phone,
                    'external_id' => $externalId ?: $contact->external_id,
                    'custom_data' => $mergedCustomData,
                    'source_reference' => $sourceReference ?: $contact->source_reference,
                ], fn ($val) => $val !== null));

                return ['contact' => $contact->fresh(), 'created' => false];
            }

            $newContact = Contact::create([
                'user_id' => $userId,
                'email' => $email,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'full_name' => $fullName,
                'company' => $company,
                'job_title' => $jobTitle,
                'phone' => $phone,
                'external_id' => $externalId,
                'custom_data' => $customData,
                'source' => $source,
                'source_reference' => $sourceReference,
                'is_active' => true,
            ]);

            return ['contact' => $newContact, 'created' => true];
        });
    }
}
