<?php

declare(strict_types=1);

namespace App\Products\MailForge\Actions\Contact;

use App\Models\User;
use App\Products\MailForge\Contracts\ContactSpreadsheetImportServiceInterface;
use App\Products\MailForge\DTOs\ExcelImportSummary;
use App\Products\MailForge\Enums\ContactSourceType;
use App\Products\MailForge\Models\Contact;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ImportContactsFromExcelAction
{
    public function __construct(
        protected ContactSpreadsheetImportServiceInterface $spreadsheetImportService
    ) {}

    /**
     * Parse spreadsheet, deduplicate, compare with existing contacts, and create new contacts.
     *
     *
     * @throws ValidationException
     */
    public function execute(User|int $user, UploadedFile|string $file): ExcelImportSummary
    {
        $userId = $user instanceof User ? (int) $user->id : (int) $user;

        // 1. Parse spreadsheet into structured deduplicated rows
        $parseResult = $this->spreadsheetImportService->parse($file);

        $candidateContacts = $parseResult->validContacts;
        $createdCount = 0;
        $alreadyExistingCount = 0;

        if (! empty($candidateContacts)) {
            $candidateEmails = array_keys($candidateContacts);

            // 2. Fetch existing contacts for this user in chunks to avoid parameter limits
            $existingEmails = [];
            foreach (array_chunk($candidateEmails, 1000) as $emailChunk) {
                $chunkResults = Contact::withTrashed()
                    ->where('user_id', $userId)
                    ->whereIn('email', $emailChunk)
                    ->pluck('email')
                    ->map(fn ($email) => strtolower((string) $email))
                    ->all();

                $existingEmails = array_merge($existingEmails, $chunkResults);
            }

            $existingMap = array_fill_keys($existingEmails, true);

            // 3. Bulk insert only non-existing contacts in chunks of 500
            $candidateChunks = array_chunk(array_values($candidateContacts), 500);
            $now = now()->toDateTimeString();

            foreach ($candidateChunks as $chunk) {
                $batchToInsert = [];

                foreach ($chunk as $candidate) {
                    $email = $candidate->normalizedEmail;

                    if (isset($existingMap[$email])) {
                        $alreadyExistingCount++;

                        continue;
                    }

                    $fullName = $candidate->fullName;
                    if (empty($fullName)) {
                        $names = array_filter([$candidate->firstName, $candidate->lastName]);
                        $fullName = ! empty($names) ? implode(' ', $names) : null;
                    }

                    $batchToInsert[] = [
                        'user_id' => $userId,
                        'email' => $candidate->normalizedEmail,
                        'first_name' => $candidate->firstName,
                        'last_name' => $candidate->lastName,
                        'full_name' => $fullName,
                        'company' => $candidate->company,
                        'job_title' => $candidate->jobTitle,
                        'phone' => $candidate->phone,
                        'external_id' => $candidate->externalId,
                        'custom_data' => ! empty($candidate->customData) ? json_encode($candidate->customData) : '{}',
                        'source' => ContactSourceType::EXCEL->value,
                        'source_reference' => null,
                        'is_active' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if (! empty($batchToInsert)) {
                    $inserted = DB::table('contacts')->insertOrIgnore($batchToInsert);
                    $createdCount += $inserted;
                    $alreadyExistingCount += (count($batchToInsert) - $inserted);
                }
            }
        }

        $duplicatesInFileCount = count($parseResult->duplicateRows);
        $invalidCount = count($parseResult->invalidRows);

        return new ExcelImportSummary(
            totalRows: $parseResult->totalRows,
            validRows: $parseResult->validRowsCount,
            created: $createdCount,
            duplicatesInFile: $duplicatesInFileCount,
            alreadyExisting: $alreadyExistingCount,
            invalid: $invalidCount,
            invalidRows: $parseResult->invalidRows
        );
    }
}
