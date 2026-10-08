<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Models\User;
use App\Products\MailForge\Enums\ContactSourceType;
use App\Products\MailForge\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ExcelContactImportTest extends TestCase
{
    use RefreshDatabase;

    protected User $userA;

    protected User $userB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userA = User::factory()->create([
            'email' => 'user.a@example.com',
            'name' => 'User A',
        ]);

        $this->userB = User::factory()->create([
            'email' => 'user.b@example.com',
            'name' => 'User B',
        ]);
    }

    /**
     * Helper to create real XLSX UploadedFile instance.
     *
     * @param  array<int, array<int, mixed>>  $rows
     */
    protected function createXlsxFile(array $rows, string $filename = 'contacts.xlsx'): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        foreach ($rows as $rIdx => $row) {
            foreach ($row as $cIdx => $val) {
                $sheet->setCellValue([$cIdx + 1, $rIdx + 1], $val);
            }
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'test_xlsx_').'.xlsx';
        $writer = new Xlsx($spreadsheet);
        $writer->save($tempPath);

        return new UploadedFile(
            path: $tempPath,
            originalName: $filename,
            mimeType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            error: null,
            test: true
        );
    }

    /**
     * 1 & 2. Valid XLSX Excel import creates contacts for authenticated user.
     */
    public function test_valid_xlsx_excel_import_creates_contacts(): void
    {
        Sanctum::actingAs($this->userA);

        $file = $this->createXlsxFile([
            ['Email', 'First Name', 'Last Name', 'Company'],
            ['alice@example.com', 'Alice', 'Smith', 'Acme Corp'],
            ['bob@example.com', 'Bob', 'Jones', 'Globex Inc'],
        ]);

        $response = $this->postJson('/api/v1/contacts/import', [
            'file' => $file,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Contacts imported successfully.')
            ->assertJsonPath('summary.total_rows', 2)
            ->assertJsonPath('summary.valid_rows', 2)
            ->assertJsonPath('summary.created', 2)
            ->assertJsonPath('summary.duplicates_in_file', 0)
            ->assertJsonPath('summary.already_existing', 0)
            ->assertJsonPath('summary.invalid', 0);

        $this->assertDatabaseHas('contacts', [
            'user_id' => $this->userA->id,
            'email' => 'alice@example.com',
            'first_name' => 'Alice',
            'last_name' => 'Smith',
            'company' => 'Acme Corp',
            'source' => ContactSourceType::EXCEL->value,
        ]);

        $this->assertDatabaseHas('contacts', [
            'user_id' => $this->userA->id,
            'email' => 'bob@example.com',
            'first_name' => 'Bob',
            'last_name' => 'Jones',
            'company' => 'Globex Inc',
            'source' => ContactSourceType::EXCEL->value,
        ]);
    }

    /**
     * 3. Missing email column returns a clear 422 validation error.
     */
    public function test_missing_email_column_returns_422(): void
    {
        Sanctum::actingAs($this->userA);

        $file = $this->createXlsxFile([
            ['First Name', 'Last Name', 'Company'],
            ['Alice', 'Smith', 'Acme Corp'],
        ]);

        $response = $this->postJson('/api/v1/contacts/import', [
            'file' => $file,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file']);

        $this->assertStringContainsString('email', $response->json('errors.file.0'));
        $this->assertDatabaseCount('contacts', 0);
    }

    /**
     * 4 & 5. Invalid and blank emails are reported in summary as invalid rows.
     */
    public function test_invalid_and_blank_emails_reported_in_summary(): void
    {
        Sanctum::actingAs($this->userA);

        $file = $this->createXlsxFile([
            ['email', 'name'],
            ['valid@example.com', 'Valid User'],
            ['not-an-email', 'Bad Email User'],
            ['', 'Blank Email User'],
            ['   ', 'Whitespace Email User'],
        ]);

        $response = $this->postJson('/api/v1/contacts/import', [
            'file' => $file,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('summary.total_rows', 4)
            ->assertJsonPath('summary.valid_rows', 1)
            ->assertJsonPath('summary.created', 1)
            ->assertJsonPath('summary.invalid', 3);

        $invalidRows = $response->json('invalid_rows');
        $this->assertCount(3, $invalidRows);
        $this->assertSame('not-an-email', $invalidRows[0]['email']);
        $this->assertDatabaseCount('contacts', 1);
    }

    /**
     * 6 & 7. Whitespace trimming and case normalization.
     */
    public function test_whitespace_trimming_and_case_normalization(): void
    {
        Sanctum::actingAs($this->userA);

        $file = $this->createXlsxFile([
            ['EMAIL', 'first_name'],
            ['  John.Doe@Example.COM  ', 'John'],
        ]);

        $response = $this->postJson('/api/v1/contacts/import', [
            'file' => $file,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('summary.created', 1);

        $this->assertDatabaseHas('contacts', [
            'user_id' => $this->userA->id,
            'email' => 'john.doe@example.com',
            'first_name' => 'John',
        ]);
    }

    /**
     * 8. Duplicate emails inside file are processed only once and reported.
     */
    public function test_duplicate_emails_inside_file_processed_once(): void
    {
        Sanctum::actingAs($this->userA);

        $file = $this->createXlsxFile([
            ['email', 'first_name'],
            ['john@example.com', 'John 1'],
            ['JOHN@example.com', 'John 2'],
            ['  john@example.com  ', 'John 3'],
            ['alice@example.com', 'Alice'],
        ]);

        $response = $this->postJson('/api/v1/contacts/import', [
            'file' => $file,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('summary.total_rows', 4)
            ->assertJsonPath('summary.valid_rows', 4)
            ->assertJsonPath('summary.created', 2)
            ->assertJsonPath('summary.duplicates_in_file', 2)
            ->assertJsonPath('summary.already_existing', 0);

        $this->assertDatabaseCount('contacts', 2);
    }

    /**
     * 9 & 10. Existing contacts skipped and mixed new + existing contacts handled.
     */
    public function test_existing_contacts_skipped_and_mixed_handled(): void
    {
        Sanctum::actingAs($this->userA);

        Contact::factory()->create([
            'user_id' => $this->userA->id,
            'email' => 'existing@example.com',
            'first_name' => 'Existing',
        ]);

        $file = $this->createXlsxFile([
            ['email', 'first_name'],
            ['existing@example.com', 'Existing Again'],
            ['EXISTING@example.com', 'Existing Casing'],
            ['new1@example.com', 'New One'],
            ['new2@example.com', 'New Two'],
        ]);

        $response = $this->postJson('/api/v1/contacts/import', [
            'file' => $file,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('summary.total_rows', 4)
            ->assertJsonPath('summary.valid_rows', 4)
            ->assertJsonPath('summary.created', 2)
            ->assertJsonPath('summary.duplicates_in_file', 1)
            ->assertJsonPath('summary.already_existing', 1);

        $this->assertDatabaseCount('contacts', 3);
    }

    /**
     * 11 & 12 & 22. Cross-user isolation and multi-user identical email support.
     */
    public function test_cross_user_isolation_and_multi_user_same_email(): void
    {
        // User B already has john@example.com
        Contact::factory()->create([
            'user_id' => $this->userB->id,
            'email' => 'john@example.com',
            'first_name' => 'User B John',
        ]);

        // User A uploads an Excel containing john@example.com
        Sanctum::actingAs($this->userA);

        $file = $this->createXlsxFile([
            ['email', 'first_name'],
            ['john@example.com', 'User A John'],
        ]);

        $response = $this->postJson('/api/v1/contacts/import', [
            'file' => $file,
            'user_id' => $this->userB->id, // Attempt to override ownership
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('summary.created', 1)
            ->assertJsonPath('summary.already_existing', 0);

        // Verify contact was created under User A, NOT User B
        $this->assertDatabaseHas('contacts', [
            'user_id' => $this->userA->id,
            'email' => 'john@example.com',
            'first_name' => 'User A John',
        ]);

        // Verify User B still has their original contact unchanged
        $this->assertDatabaseHas('contacts', [
            'user_id' => $this->userB->id,
            'email' => 'john@example.com',
            'first_name' => 'User B John',
        ]);

        $this->assertDatabaseCount('contacts', 2);
    }

    /**
     * 13. Empty spreadsheet returns 422.
     */
    public function test_empty_spreadsheet_returns_422(): void
    {
        Sanctum::actingAs($this->userA);

        $file = $this->createXlsxFile([]);

        $response = $this->postJson('/api/v1/contacts/import', [
            'file' => $file,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    /**
     * 14. Malformed spreadsheet file returns 422.
     */
    public function test_malformed_spreadsheet_returns_422(): void
    {
        Sanctum::actingAs($this->userA);

        $file = UploadedFile::fake()->createWithContent('corrupt.xlsx', 'this is not valid xlsx zip binary data');

        $response = $this->postJson('/api/v1/contacts/import', [
            'file' => $file,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    /**
     * 15. Unsupported file type returns 422.
     */
    public function test_unsupported_file_type_returns_422(): void
    {
        Sanctum::actingAs($this->userA);

        $file = UploadedFile::fake()->create('contacts.pdf', 100, 'application/pdf');

        $response = $this->postJson('/api/v1/contacts/import', [
            'file' => $file,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    /**
     * 16. File larger than max limit returns 422.
     */
    public function test_file_too_large_returns_422(): void
    {
        Sanctum::actingAs($this->userA);

        // 11MB file exceeds 10240 KB limit
        $file = UploadedFile::fake()->create('large.xlsx', 11264, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $response = $this->postJson('/api/v1/contacts/import', [
            'file' => $file,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    /**
     * 17 & 18 & 19. Optional fields (first_name, last_name, full_name, company, phone, job_title) imported.
     */
    public function test_optional_contact_fields_imported(): void
    {
        Sanctum::actingAs($this->userA);

        $file = $this->createXlsxFile([
            ['Email', 'First Name', 'Last Name', 'Company', 'Job Title', 'Phone', 'External ID', 'Department'],
            ['sarah@example.com', 'Sarah', 'Connor', 'Resistance', 'Leader', '123456789', 'RES-01', 'Operations'],
        ]);

        $response = $this->postJson('/api/v1/contacts/import', [
            'file' => $file,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('summary.created', 1);

        $contact = Contact::where('email', 'sarah@example.com')->first();
        $this->assertNotNull($contact);
        $this->assertSame('Sarah', $contact->first_name);
        $this->assertSame('Connor', $contact->last_name);
        $this->assertSame('Sarah Connor', $contact->full_name);
        $this->assertSame('Resistance', $contact->company);
        $this->assertSame('Leader', $contact->job_title);
        $this->assertSame('123456789', $contact->phone);
        $this->assertSame('RES-01', $contact->external_id);
        $this->assertSame(['Department' => 'Operations'], $contact->custom_data);
    }

    /**
     * 20. Import summary structure validation.
     */
    public function test_import_result_summary_structure(): void
    {
        Sanctum::actingAs($this->userA);

        $file = $this->createXlsxFile([
            ['email'],
            ['user1@example.com'],
            ['user2@example.com'],
        ]);

        $response = $this->postJson('/api/v1/contacts/import', [
            'file' => $file,
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'summary' => [
                    'total_rows',
                    'valid_rows',
                    'created',
                    'duplicates_in_file',
                    'already_existing',
                    'invalid',
                ],
                'invalid_rows',
            ]);
    }

    /**
     * 21. Database uniqueness and race condition handling.
     */
    public function test_database_uniqueness_protection_with_soft_deleted_contacts(): void
    {
        Sanctum::actingAs($this->userA);

        // Soft deleted contact for userA
        $contact = Contact::factory()->create([
            'user_id' => $this->userA->id,
            'email' => 'softdeleted@example.com',
        ]);
        $contact->delete();

        $file = $this->createXlsxFile([
            ['email'],
            ['softdeleted@example.com'],
            ['brandnew@example.com'],
        ]);

        $response = $this->postJson('/api/v1/contacts/import', [
            'file' => $file,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('summary.created', 1)
            ->assertJsonPath('summary.already_existing', 1);

        $this->assertDatabaseHas('contacts', [
            'user_id' => $this->userA->id,
            'email' => 'brandnew@example.com',
        ]);
    }

    /**
     * 23. API test unauthenticated returns 401.
     */
    public function test_unauthenticated_request_returns_401(): void
    {
        $file = UploadedFile::fake()->create('test.xlsx', 10);

        $response = $this->postJson('/api/v1/contacts/import', [
            'file' => $file,
        ]);

        $response->assertStatus(401);
    }

    /**
     * CSV import is also supported seamlessly.
     */
    public function test_csv_file_import_is_supported(): void
    {
        Sanctum::actingAs($this->userA);

        $csvContent = "Email,First Name,Company\ncsv.user1@example.com,CSV One,Alpha Corp\ncsv.user2@example.com,CSV Two,Beta Corp\n";
        $file = UploadedFile::fake()->createWithContent('contacts.csv', $csvContent);

        $response = $this->postJson('/api/v1/contacts/import', [
            'file' => $file,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('summary.created', 2);

        $this->assertDatabaseHas('contacts', [
            'user_id' => $this->userA->id,
            'email' => 'csv.user1@example.com',
            'first_name' => 'CSV One',
            'company' => 'Alpha Corp',
        ]);
    }

    /**
     * Subscriber-prefixed CRM headers (subscriber_email, subscriber_fname, etc.) are recognized.
     */
    public function test_subscriber_and_crm_format_csv_headers(): void
    {
        Sanctum::actingAs($this->userA);

        $csvContent = "subscriber_email,subscriber_fname,subscriber_lname,subscriber_phone,subscriber_city\nheadada0@juno.com,Abdul,Shields,3134216416,Dearborn\n";
        $file = UploadedFile::fake()->createWithContent('subscribers.csv', $csvContent);

        $response = $this->postJson('/api/v1/contacts/import', [
            'file' => $file,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('summary.created', 1);

        $this->assertDatabaseHas('contacts', [
            'user_id' => $this->userA->id,
            'email' => 'headada0@juno.com',
            'first_name' => 'Abdul',
            'last_name' => 'Shields',
            'full_name' => 'Abdul Shields',
            'phone' => '3134216416',
        ]);
    }
}
