<?php

declare(strict_types=1);

namespace App\Products\MailForge\Contracts;

use App\Products\MailForge\DTOs\SpreadsheetParseResult;
use Illuminate\Http\UploadedFile;

interface ContactSpreadsheetImportServiceInterface
{
    /**
     * Parse and extract validated, deduplicated contacts from a spreadsheet file.
     */
    public function parse(UploadedFile|string $file): SpreadsheetParseResult;
}
