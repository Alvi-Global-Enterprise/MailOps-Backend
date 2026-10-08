<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Models\User;
use App\Products\MailForge\Enums\ContactSourceType;
use App\Products\MailForge\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ContactApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $userA;

    protected User $userB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userA = User::factory()->create();
        $this->userB = User::factory()->create();
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->getJson('/api/v1/contacts');

        $response->assertStatus(401);
    }

    public function test_user_can_list_own_contacts_only(): void
    {
        Sanctum::actingAs($this->userA);

        Contact::factory()->count(3)->create(['user_id' => $this->userA->id]);
        Contact::factory()->count(2)->create(['user_id' => $this->userB->id]);

        $response = $this->getJson('/api/v1/contacts');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ])
            ->assertJsonCount(3, 'data');
    }

    public function test_contacts_pagination_works(): void
    {
        Sanctum::actingAs($this->userA);

        Contact::factory()->count(15)->create(['user_id' => $this->userA->id]);

        $response = $this->getJson('/api/v1/contacts?per_page=5&page=2');

        $response->assertStatus(200)
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.per_page', 5)
            ->assertJsonPath('meta.total', 15);
    }

    public function test_contacts_search_by_name_and_email(): void
    {
        Sanctum::actingAs($this->userA);

        Contact::factory()->create([
            'user_id' => $this->userA->id,
            'first_name' => 'Alexander',
            'last_name' => 'Hamilton',
            'email' => 'alex@example.com',
        ]);

        Contact::factory()->create([
            'user_id' => $this->userA->id,
            'first_name' => 'Thomas',
            'last_name' => 'Jefferson',
            'email' => 'thomas@example.com',
        ]);

        $response = $this->getJson('/api/v1/contacts?search=alexander');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', 'alex@example.com');
    }

    public function test_contacts_filtering_by_source(): void
    {
        Sanctum::actingAs($this->userA);

        Contact::factory()->create([
            'user_id' => $this->userA->id,
            'source' => ContactSourceType::MANUAL,
        ]);

        Contact::factory()->create([
            'user_id' => $this->userA->id,
            'source' => ContactSourceType::GOOGLE_SHEETS,
        ]);

        $response = $this->getJson('/api/v1/contacts?source=google_sheets');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.source', 'google_sheets');
    }

    public function test_user_can_create_a_valid_contact(): void
    {
        Sanctum::actingAs($this->userA);

        $payload = [
            'email' => 'NewContact@Example.COM',
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'company' => 'Analytical Engine Co',
            'custom_data' => ['tier' => 'VIP', 'score' => 98],
        ];

        $response = $this->postJson('/api/v1/contacts', $payload);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'email' => 'newcontact@example.com',
                    'first_name' => 'Ada',
                    'last_name' => 'Lovelace',
                    'company' => 'Analytical Engine Co',
                    'custom_data' => ['tier' => 'VIP', 'score' => 98],
                ],
            ]);

        $this->assertDatabaseHas('contacts', [
            'user_id' => $this->userA->id,
            'email' => 'newcontact@example.com',
        ]);
    }

    public function test_create_contact_with_invalid_email_fails_validation(): void
    {
        Sanctum::actingAs($this->userA);

        $response = $this->postJson('/api/v1/contacts', [
            'email' => 'not-an-email',
            'first_name' => 'Invalid',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_create_duplicate_contact_email_for_same_user_is_rejected(): void
    {
        Sanctum::actingAs($this->userA);

        Contact::factory()->create([
            'user_id' => $this->userA->id,
            'email' => 'duplicate@example.com',
        ]);

        $response = $this->postJson('/api/v1/contacts', [
            'email' => 'duplicate@example.com',
            'first_name' => 'Second',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_different_users_can_create_contacts_with_same_email(): void
    {
        Contact::factory()->create([
            'user_id' => $this->userB->id,
            'email' => 'shared@example.com',
        ]);

        Sanctum::actingAs($this->userA);

        $response = $this->postJson('/api/v1/contacts', [
            'email' => 'shared@example.com',
            'first_name' => 'User A Copy',
        ]);

        $response->assertStatus(201);
    }

    public function test_user_can_view_own_contact(): void
    {
        Sanctum::actingAs($this->userA);

        $contact = Contact::factory()->create([
            'user_id' => $this->userA->id,
            'email' => 'view@example.com',
        ]);

        $response = $this->getJson("/api/v1/contacts/{$contact->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $contact->id)
            ->assertJsonPath('data.email', 'view@example.com');
    }

    public function test_user_cannot_view_another_users_contact(): void
    {
        Sanctum::actingAs($this->userA);

        $contactB = Contact::factory()->create([
            'user_id' => $this->userB->id,
        ]);

        $response = $this->getJson("/api/v1/contacts/{$contactB->id}");

        $response->assertStatus(403);
    }

    public function test_user_can_update_own_contact_and_preserve_custom_data(): void
    {
        Sanctum::actingAs($this->userA);

        $contact = Contact::factory()->create([
            'user_id' => $this->userA->id,
            'first_name' => 'Original',
            'custom_data' => ['tag' => 'old_tag', 'keep' => 'value'],
        ]);

        $response = $this->putJson("/api/v1/contacts/{$contact->id}", [
            'first_name' => 'Updated Name',
            'custom_data' => ['tag' => 'new_tag', 'extra' => 'info'],
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.first_name', 'Updated Name')
            ->assertJsonPath('data.custom_data.tag', 'new_tag')
            ->assertJsonPath('data.custom_data.keep', 'value')
            ->assertJsonPath('data.custom_data.extra', 'info');
    }

    public function test_user_cannot_update_another_users_contact(): void
    {
        Sanctum::actingAs($this->userA);

        $contactB = Contact::factory()->create([
            'user_id' => $this->userB->id,
            'first_name' => 'User B Contact',
        ]);

        $response = $this->putJson("/api/v1/contacts/{$contactB->id}", [
            'first_name' => 'Hacked',
        ]);

        $response->assertStatus(403);
    }

    public function test_user_can_delete_own_contact(): void
    {
        Sanctum::actingAs($this->userA);

        $contact = Contact::factory()->create([
            'user_id' => $this->userA->id,
        ]);

        $response = $this->deleteJson("/api/v1/contacts/{$contact->id}");

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertSoftDeleted('contacts', ['id' => $contact->id]);
    }

    public function test_user_cannot_delete_another_users_contact(): void
    {
        Sanctum::actingAs($this->userA);

        $contactB = Contact::factory()->create([
            'user_id' => $this->userB->id,
        ]);

        $response = $this->deleteJson("/api/v1/contacts/{$contactB->id}");

        $response->assertStatus(403);
        $this->assertDatabaseHas('contacts', ['id' => $contactB->id, 'deleted_at' => null]);
    }
}
