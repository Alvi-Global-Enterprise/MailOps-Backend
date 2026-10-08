<?php

declare(strict_types=1);

namespace App\Products\MailForge\DTOs;

readonly class SheetSyncResult
{
    /**
     * @param  list<SheetRowData>  $validRows
     * @param  list<SheetRowData>  $invalidRows
     * @param  list<string>  $duplicateEmails
     * @param  list<string>  $headers
     */
    public function __construct(
        public string $spreadsheetId,
        public string $sheetName,
        public int $totalRows,
        public array $validRows,
        public array $invalidRows,
        public int $skippedEmptyRows,
        public array $duplicateEmails,
        public array $headers
    ) {}

    public function validCount(): int
    {
        return count($this->validRows);
    }

    public function invalidCount(): int
    {
        return count($this->invalidRows);
    }

    public function duplicateCount(): int
    {
        return count($this->duplicateEmails);
    }
}
