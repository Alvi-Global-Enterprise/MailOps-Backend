<?php

declare(strict_types=1);

namespace App\Products\MailForge\DTOs;

readonly class ExcelImportSummary
{
    /**
     * @param  array<int, array{row: int, email: ?string, reason: string}>  $invalidRows
     */
    public function __construct(
        public int $totalRows,
        public int $validRows,
        public int $created,
        public int $duplicatesInFile,
        public int $alreadyExisting,
        public int $invalid,
        public array $invalidRows = []
    ) {}

    /**
     * Convert to array response format.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'total_rows' => $this->totalRows,
            'valid_rows' => $this->validRows,
            'created' => $this->created,
            'duplicates_in_file' => $this->duplicatesInFile,
            'already_existing' => $this->alreadyExisting,
            'invalid' => $this->invalid,
        ];
    }
}
