<?php

declare(strict_types=1);

namespace App\Products\MailForge\DTOs;

readonly class SpreadsheetParseResult
{
    /**
     * @param  array<string, ParsedSpreadsheetContact>  $validContacts  Keyed by normalized email (file-deduplicated)
     * @param  array<int, array{row: int, email: string, reason: string}>  $duplicateRows
     * @param  array<int, array{row: int, email: ?string, reason: string}>  $invalidRows
     */
    public function __construct(
        public int $totalRows,
        public int $validRowsCount,
        public array $validContacts,
        public array $duplicateRows,
        public array $invalidRows
    ) {}
}
