<?php

declare(strict_types=1);

namespace App\Products\MailForge\Services\Import;

use App\Products\MailForge\Contracts\ContactSpreadsheetImportServiceInterface;
use App\Products\MailForge\DTOs\ParsedSpreadsheetContact;
use App\Products\MailForge\DTOs\SpreadsheetParseResult;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;

class ExcelContactImportService implements ContactSpreadsheetImportServiceInterface
{
    /**
     * Map of normalized header aliases to model attribute names.
     *
     * @var array<string, string>
     */
    protected array $headerAliases = [
        'email' => 'email',
        'e-mail' => 'email',
        'e_mail' => 'email',
        'email address' => 'email',
        'email_address' => 'email',
        'emailaddress' => 'email',
        'subscriber_email' => 'email',
        'subscriber email' => 'email',
        'subscriberemail' => 'email',
        'contact_email' => 'email',
        'contact email' => 'email',
        'customer_email' => 'email',
        'client_email' => 'email',
        'user_email' => 'email',
        'lead_email' => 'email',
        'member_email' => 'email',
        'email_id' => 'email',
        'email id' => 'email',
        'mail_id' => 'email',
        'mail' => 'email',
        'emails' => 'email',
        'primary_email' => 'email',
        'work_email' => 'email',
        'personal_email' => 'email',
        'recipient_email' => 'email',

        'first_name' => 'first_name',
        'firstname' => 'first_name',
        'first name' => 'first_name',
        'fname' => 'first_name',
        'subscriber_fname' => 'first_name',
        'subscriber fname' => 'first_name',
        'subscriber_first_name' => 'first_name',
        'contact_fname' => 'first_name',

        'last_name' => 'last_name',
        'lastname' => 'last_name',
        'last name' => 'last_name',
        'lname' => 'last_name',
        'subscriber_lname' => 'last_name',
        'subscriber lname' => 'last_name',
        'subscriber_last_name' => 'last_name',
        'contact_lname' => 'last_name',
        'surname' => 'last_name',

        'full_name' => 'full_name',
        'fullname' => 'full_name',
        'full name' => 'full_name',
        'name' => 'full_name',
        'subscriber_name' => 'full_name',
        'contact_name' => 'full_name',

        'company' => 'company',
        'organization' => 'company',
        'company name' => 'company',
        'company_name' => 'company',

        'job_title' => 'job_title',
        'jobtitle' => 'job_title',
        'job title' => 'job_title',
        'title' => 'job_title',
        'designation' => 'job_title',

        'phone' => 'phone',
        'phone number' => 'phone',
        'phone_number' => 'phone',
        'phonenumber' => 'phone',
        'mobile' => 'phone',
        'mobile_number' => 'phone',
        'cell' => 'phone',
        'telephone' => 'phone',
        'subscriber_phone' => 'phone',
        'contact_phone' => 'phone',

        'external_id' => 'external_id',
        'externalid' => 'external_id',
        'external id' => 'external_id',
        'id' => 'external_id',
        'subscriber_id' => 'external_id',
    ];

    /**
     * Parse and extract validated, deduplicated contacts from a spreadsheet file.
     *
     * @throws ValidationException
     */
    public function parse(UploadedFile|string $file): SpreadsheetParseResult
    {
        // Elevate memory limit and execution time for large imports
        @ini_set('memory_limit', '512M');
        @set_time_limit(300);

        $filePath = $file instanceof UploadedFile ? $file->getRealPath() : $file;

        if (! $filePath || ! file_exists($filePath)) {
            throw ValidationException::withMessages([
                'file' => ['The uploaded spreadsheet file could not be read or does not exist.'],
            ]);
        }

        $extension = '';
        if ($file instanceof UploadedFile) {
            $extension = strtolower((string) $file->getClientOriginalExtension());
        } elseif (is_string($file)) {
            $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        }

        // Fast-path: Native low-memory streaming for CSV files
        if ($extension === 'csv') {
            return $this->parseCsv($filePath);
        }

        return $this->parseSpreadsheet($filePath);
    }

    /**
     * Memory-efficient streaming parser for CSV files.
     *
     * @throws ValidationException
     */
    protected function parseCsv(string $filePath): SpreadsheetParseResult
    {
        $handle = @fopen($filePath, 'r');
        if ($handle === false) {
            throw ValidationException::withMessages([
                'file' => ['The uploaded spreadsheet file could not be read or does not exist.'],
            ]);
        }

        // Detect delimiter and strip UTF-8 BOM if present
        $firstLine = fgets($handle);
        if ($firstLine === false) {
            fclose($handle);
            throw ValidationException::withMessages([
                'file' => ['The uploaded spreadsheet is empty or contains no readable sheets.'],
            ]);
        }

        $bom = pack('H*', 'EFBBBF');
        $firstLine = preg_replace("/^$bom/", '', $firstLine);

        $delimiter = ',';
        $semicolons = substr_count($firstLine, ';');
        $commas = substr_count($firstLine, ',');
        $tabs = substr_count($firstLine, "\t");
        if ($semicolons > $commas && $semicolons > $tabs) {
            $delimiter = ';';
        } elseif ($tabs > $commas && $tabs > $semicolons) {
            $delimiter = "\t";
        }

        rewind($handle);

        $headers = null;
        $columnIndexMap = null;
        $fileLineNumber = 0;

        $validContacts = [];
        $duplicateRows = [];
        $invalidRows = [];
        $totalDataRows = 0;

        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $fileLineNumber++;

            if ($fileLineNumber === 1 && isset($row[0])) {
                $row[0] = preg_replace("/^$bom/", '', (string) $row[0]);
            }

            if ($this->isRowEmpty($row)) {
                continue;
            }

            if ($headers === null) {
                $headers = $row;
                $columnIndexMap = $this->mapHeaders($headers);

                if (! isset($columnIndexMap['email'])) {
                    fclose($handle);
                    throw ValidationException::withMessages([
                        'file' => ['The spreadsheet must contain an "email" column header.'],
                    ]);
                }

                continue;
            }

            $totalDataRows++;
            $excelRowNumber = $fileLineNumber;
            $emailColIdx = $columnIndexMap['email'];

            $rawEmailVal = $row[$emailColIdx] ?? null;
            $rawEmail = is_string($rawEmailVal) || is_numeric($rawEmailVal) ? (string) $rawEmailVal : '';
            $trimmedEmail = trim($rawEmail);

            if ($trimmedEmail === '') {
                $invalidRows[] = [
                    'row' => $excelRowNumber,
                    'email' => '',
                    'reason' => 'Email address is blank or missing.',
                ];

                continue;
            }

            $normalizedEmail = strtolower($trimmedEmail);

            if (filter_var($normalizedEmail, FILTER_VALIDATE_EMAIL) === false) {
                $invalidRows[] = [
                    'row' => $excelRowNumber,
                    'email' => $trimmedEmail,
                    'reason' => 'Invalid email address format.',
                ];

                continue;
            }

            if (isset($validContacts[$normalizedEmail])) {
                $duplicateRows[] = [
                    'row' => $excelRowNumber,
                    'email' => $normalizedEmail,
                    'reason' => 'Duplicate email within uploaded spreadsheet.',
                ];

                continue;
            }

            $firstName = $this->getCleanCell($row, $columnIndexMap['first_name'] ?? null);
            $lastName = $this->getCleanCell($row, $columnIndexMap['last_name'] ?? null);
            $fullName = $this->getCleanCell($row, $columnIndexMap['full_name'] ?? null);
            $company = $this->getCleanCell($row, $columnIndexMap['company'] ?? null);
            $jobTitle = $this->getCleanCell($row, $columnIndexMap['job_title'] ?? null);
            $phone = $this->getCleanCell($row, $columnIndexMap['phone'] ?? null);
            $externalId = $this->getCleanCell($row, $columnIndexMap['external_id'] ?? null);

            $customData = [];
            foreach ($row as $colIdx => $cellValue) {
                if (in_array($colIdx, $columnIndexMap, true)) {
                    continue;
                }
                $headerName = isset($headers[$colIdx]) ? trim((string) $headers[$colIdx]) : null;
                if ($headerName !== null && $headerName !== '' && $cellValue !== null && $cellValue !== '') {
                    $customData[$headerName] = is_string($cellValue) ? trim($cellValue) : $cellValue;
                }
            }

            $validContacts[$normalizedEmail] = new ParsedSpreadsheetContact(
                rowIndex: $excelRowNumber,
                rawEmail: $trimmedEmail,
                normalizedEmail: $normalizedEmail,
                firstName: $firstName,
                lastName: $lastName,
                fullName: $fullName,
                company: $company,
                jobTitle: $jobTitle,
                phone: $phone,
                externalId: $externalId,
                customData: $customData,
                isValid: true
            );
        }

        fclose($handle);

        if ($headers === null) {
            throw ValidationException::withMessages([
                'file' => ['The uploaded spreadsheet contains no data or headers.'],
            ]);
        }

        if ($totalDataRows === 0) {
            throw ValidationException::withMessages([
                'file' => ['The uploaded spreadsheet contains no contact rows to import.'],
            ]);
        }

        $validRowsCount = count($validContacts) + count($duplicateRows);

        return new SpreadsheetParseResult(
            totalRows: $totalDataRows,
            validRowsCount: $validRowsCount,
            validContacts: $validContacts,
            duplicateRows: $duplicateRows,
            invalidRows: $invalidRows
        );
    }

    /**
     * Memory-optimized parser for XLSX and XLS spreadsheets.
     *
     * @throws ValidationException
     */
    protected function parseSpreadsheet(string $filePath): SpreadsheetParseResult
    {
        try {
            $reader = IOFactory::createReaderForFile($filePath);
            $reader->setReadDataOnly(true);
            if (method_exists($reader, 'setReadEmptyCells')) {
                $reader->setReadEmptyCells(false);
            }
            if (method_exists($reader, 'setIgnoreRowsWithNoCells')) {
                $reader->setIgnoreRowsWithNoCells(true);
            }
            if (method_exists($reader, 'setIncludeCharts')) {
                $reader->setIncludeCharts(false);
            }

            $spreadsheet = $reader->load($filePath);
            $worksheet = $spreadsheet->getActiveSheet();
            $rawRows = $worksheet->toArray(null, false, false, false);

            // Free cell memory immediately
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet, $worksheet, $reader);
            gc_collect_cycles();
        } catch (Throwable $e) {
            throw ValidationException::withMessages([
                'file' => ['Unable to read spreadsheet: '.$e->getMessage()],
            ]);
        }

        if (empty($rawRows)) {
            throw ValidationException::withMessages([
                'file' => ['The uploaded spreadsheet is empty or contains no readable sheets.'],
            ]);
        }

        // 1. Locate header row (first non-empty row)
        $headerRowIndex = null;
        $headers = [];

        foreach ($rawRows as $index => $row) {
            if ($this->isRowEmpty($row)) {
                continue;
            }
            $headerRowIndex = $index;
            $headers = $row;
            break;
        }

        if ($headerRowIndex === null) {
            throw ValidationException::withMessages([
                'file' => ['The uploaded spreadsheet contains no data or headers.'],
            ]);
        }

        // 2. Map headers case-insensitively
        $columnIndexMap = $this->mapHeaders($headers);

        if (! isset($columnIndexMap['email'])) {
            throw ValidationException::withMessages([
                'file' => ['The spreadsheet must contain an "email" column header.'],
            ]);
        }

        $emailColIdx = $columnIndexMap['email'];

        // 3. Process data rows
        $validContacts = [];
        $duplicateRows = [];
        $invalidRows = [];
        $totalDataRows = 0;

        $totalRowCount = count($rawRows);
        for ($i = $headerRowIndex + 1; $i < $totalRowCount; $i++) {
            $row = $rawRows[$i];
            unset($rawRows[$i]); // Free row memory immediately

            if ($this->isRowEmpty($row)) {
                continue;
            }

            $totalDataRows++;
            $excelRowNumber = $i + 1;

            $rawEmailVal = $row[$emailColIdx] ?? null;
            $rawEmail = is_string($rawEmailVal) || is_numeric($rawEmailVal) ? (string) $rawEmailVal : '';
            $trimmedEmail = trim($rawEmail);

            if ($trimmedEmail === '') {
                $invalidRows[] = [
                    'row' => $excelRowNumber,
                    'email' => '',
                    'reason' => 'Email address is blank or missing.',
                ];

                continue;
            }

            $normalizedEmail = strtolower($trimmedEmail);

            if (filter_var($normalizedEmail, FILTER_VALIDATE_EMAIL) === false) {
                $invalidRows[] = [
                    'row' => $excelRowNumber,
                    'email' => $trimmedEmail,
                    'reason' => 'Invalid email address format.',
                ];

                continue;
            }

            if (isset($validContacts[$normalizedEmail])) {
                $duplicateRows[] = [
                    'row' => $excelRowNumber,
                    'email' => $normalizedEmail,
                    'reason' => 'Duplicate email within uploaded spreadsheet.',
                ];

                continue;
            }

            $firstName = $this->getCleanCell($row, $columnIndexMap['first_name'] ?? null);
            $lastName = $this->getCleanCell($row, $columnIndexMap['last_name'] ?? null);
            $fullName = $this->getCleanCell($row, $columnIndexMap['full_name'] ?? null);
            $company = $this->getCleanCell($row, $columnIndexMap['company'] ?? null);
            $jobTitle = $this->getCleanCell($row, $columnIndexMap['job_title'] ?? null);
            $phone = $this->getCleanCell($row, $columnIndexMap['phone'] ?? null);
            $externalId = $this->getCleanCell($row, $columnIndexMap['external_id'] ?? null);

            $customData = [];
            foreach ($row as $colIdx => $cellValue) {
                if (in_array($colIdx, $columnIndexMap, true)) {
                    continue;
                }
                $headerName = isset($headers[$colIdx]) ? trim((string) $headers[$colIdx]) : null;
                if ($headerName !== null && $headerName !== '' && $cellValue !== null && $cellValue !== '') {
                    $customData[$headerName] = is_string($cellValue) ? trim($cellValue) : $cellValue;
                }
            }

            $validContacts[$normalizedEmail] = new ParsedSpreadsheetContact(
                rowIndex: $excelRowNumber,
                rawEmail: $trimmedEmail,
                normalizedEmail: $normalizedEmail,
                firstName: $firstName,
                lastName: $lastName,
                fullName: $fullName,
                company: $company,
                jobTitle: $jobTitle,
                phone: $phone,
                externalId: $externalId,
                customData: $customData,
                isValid: true
            );
        }

        unset($rawRows);
        gc_collect_cycles();

        if ($totalDataRows === 0) {
            throw ValidationException::withMessages([
                'file' => ['The uploaded spreadsheet contains no contact rows to import.'],
            ]);
        }

        $validRowsCount = count($validContacts) + count($duplicateRows);

        return new SpreadsheetParseResult(
            totalRows: $totalDataRows,
            validRowsCount: $validRowsCount,
            validContacts: $validContacts,
            duplicateRows: $duplicateRows,
            invalidRows: $invalidRows
        );
    }

    /**
     * Map row headers to recognized contact attributes.
     *
     * @param  array<int, mixed>  $headerRow
     * @return array<string, int> Map of attribute name => column index
     */
    protected function mapHeaders(array $headerRow): array
    {
        $map = [];

        foreach ($headerRow as $colIndex => $headerValue) {
            if ($headerValue === null || $headerValue === '') {
                continue;
            }

            $normalizedHeader = strtolower(trim((string) $headerValue));

            if (isset($this->headerAliases[$normalizedHeader])) {
                $attribute = $this->headerAliases[$normalizedHeader];
                if (! isset($map[$attribute])) {
                    $map[$attribute] = (int) $colIndex;
                }
            }
        }

        // Smart fallback detection:
        // 1. Email: any header containing email / e-mail / e_mail
        if (! isset($map['email'])) {
            foreach ($headerRow as $colIndex => $headerValue) {
                if ($headerValue === null || $headerValue === '') {
                    continue;
                }
                $clean = strtolower(trim((string) $headerValue));
                if (str_contains($clean, 'email') || str_contains($clean, 'e-mail') || str_contains($clean, 'e_mail') || $clean === 'mail') {
                    $map['email'] = (int) $colIndex;
                    break;
                }
            }
        }

        // 2. First name
        if (! isset($map['first_name']) && ! isset($map['full_name'])) {
            foreach ($headerRow as $colIndex => $headerValue) {
                if ($headerValue === null || $headerValue === '') {
                    continue;
                }
                $clean = strtolower(trim((string) $headerValue));
                if (preg_match('/(fname|first[_\s-]?name)/i', $clean)) {
                    $map['first_name'] = (int) $colIndex;
                    break;
                }
            }
        }

        // 3. Last name
        if (! isset($map['last_name'])) {
            foreach ($headerRow as $colIndex => $headerValue) {
                if ($headerValue === null || $headerValue === '') {
                    continue;
                }
                $clean = strtolower(trim((string) $headerValue));
                if (preg_match('/(lname|last[_\s-]?name|surname)/i', $clean)) {
                    $map['last_name'] = (int) $colIndex;
                    break;
                }
            }
        }

        // 4. Phone
        if (! isset($map['phone'])) {
            foreach ($headerRow as $colIndex => $headerValue) {
                if ($headerValue === null || $headerValue === '') {
                    continue;
                }
                $clean = strtolower(trim((string) $headerValue));
                if (preg_match('/(phone|mobile|cell|telephone)/i', $clean)) {
                    $map['phone'] = (int) $colIndex;
                    break;
                }
            }
        }

        return $map;
    }

    /**
     * Determine if an entire row contains no text or data.
     *
     * @param  array<int, mixed>  $row
     */
    protected function isRowEmpty(array $row): bool
    {
        foreach ($row as $val) {
            if ($val !== null && trim((string) $val) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Get and trim a cell value by column index.
     *
     * @param  array<int, mixed>  $row
     */
    protected function getCleanCell(array $row, ?int $colIndex): ?string
    {
        if ($colIndex === null || ! array_key_exists($colIndex, $row)) {
            return null;
        }

        $val = $row[$colIndex];
        if ($val === null) {
            return null;
        }

        $trimmed = trim((string) $val);

        return $trimmed !== '' ? $trimmed : null;
    }
}
