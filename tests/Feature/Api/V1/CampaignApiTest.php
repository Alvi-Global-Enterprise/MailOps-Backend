<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Models\User;
use App\Products\MailForge\Actions\Campaign\CreateCampaignRecipientsAction;
use App\Products\MailForge\Enums\CampaignStatus;
use App\Products\MailForge\Enums\EmailMode;
use App\Products\MailForge\Enums\RecipientDeliveryStatus;
use App\Products\MailForge\Jobs\GenerateCampaignEmailJob;
use App\Products\MailForge\Jobs\SendCampaignEmailJob;
use App\Products\MailForge\Models\Campaign;
use App\Products\MailForge\Models\CampaignRecipient;
use App\Products\MailForge\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CampaignApiTest extends TestCase
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

    /*
    |--------------------------------------------------------------------------
    | Authentication & Ownership Tests
    |--------------------------------------------------------------------------
    */

    public function test_unauthenticated_campaign_requests_are_rejected(): void
    {
        $this->getJson('/api/v1/campaigns')->assertStatus(401);
        $this->postJson('/api/v1/campaigns', [])->assertStatus(401);
        $this->getJson('/api/v1/campaigns/1')->assertStatus(401);
        $this->putJson('/api/v1/campaigns/1', [])->assertStatus(401);
        $this->deleteJson('/api/v1/campaigns/1')->assertStatus(401);
        $this->getJson('/api/v1/campaigns/1/status')->assertStatus(401);
        $this->getJson('/api/v1/campaigns/1/recipients')->assertStatus(401);
        $this->postJson('/api/v1/campaigns/1/recipients', [])->assertStatus(401);
        $this->postJson('/api/v1/campaigns/1/generate')->assertStatus(401);
        $this->postJson('/api/v1/campaigns/1/approve')->assertStatus(401);
        $this->postJson('/api/v1/campaigns/1/dispatch')->assertStatus(401);
        $this->postJson('/api/v1/campaigns/1/pause')->assertStatus(401);
        $this->postJson('/api/v1/campaigns/1/resume')->assertStatus(401);
        $this->postJson('/api/v1/campaigns/1/cancel')->assertStatus(401);
        $this->getJson('/api/v1/campaigns/1/delivery-status')->assertStatus(401);
    }

    public function test_user_can_list_own_campaigns_only(): void
    {
        Sanctum::actingAs($this->userA);

        Campaign::factory()->count(3)->create(['user_id' => $this->userA->id]);
        Campaign::factory()->count(2)->create(['user_id' => $this->userB->id]);

        $response = $this->getJson('/api/v1/campaigns');

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonCount(3, 'data');
    }

    public function test_campaign_listing_pagination_search_filtering_and_sorting(): void
    {
        Sanctum::actingAs($this->userA);

        Campaign::factory()->create([
            'user_id' => $this->userA->id,
            'name' => 'Invoice Reminder Campaign',
            'status' => CampaignStatus::DRAFT,
            'email_mode' => EmailMode::DIRECT,
            'created_at' => now()->subDays(2),
        ]);

        Campaign::factory()->create([
            'user_id' => $this->userA->id,
            'name' => 'Product Launch Newsletter',
            'status' => CampaignStatus::REVIEW,
            'email_mode' => EmailMode::GENERATE,
            'created_at' => now()->subDay(),
        ]);

        Campaign::factory()->create([
            'user_id' => $this->userA->id,
            'name' => 'Seasonal Discounts Offer',
            'status' => CampaignStatus::DRAFT,
            'email_mode' => EmailMode::POLISH,
            'created_at' => now(),
        ]);

        // Search test
        $searchRes = $this->getJson('/api/v1/campaigns?search=Invoice');
        $searchRes->assertStatus(200)->assertJsonCount(1, 'data');

        // Status filter test
        $statusRes = $this->getJson('/api/v1/campaigns?status=draft');
        $statusRes->assertStatus(200)->assertJsonCount(2, 'data');

        // Email mode filter test
        $modeRes = $this->getJson('/api/v1/campaigns?email_mode=generate');
        $modeRes->assertStatus(200)->assertJsonCount(1, 'data');

        // Pagination test
        $pageRes = $this->getJson('/api/v1/campaigns?per_page=2&page=1');
        $pageRes->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.per_page', 2);

        // Sorting test (name asc)
        $sortRes = $this->getJson('/api/v1/campaigns?sort=name&direction=asc');
        $sortRes->assertStatus(200);
        $this->assertSame('Invoice Reminder Campaign', $sortRes->json('data.0.name'));

        // Disallowed / arbitrary sort falls back safely
        $safeSortRes = $this->getJson('/api/v1/campaigns?sort=non_existent_column');
        $safeSortRes->assertStatus(200);
    }

    public function test_user_cannot_view_another_users_campaign(): void
    {
        Sanctum::actingAs($this->userA);
        $campaignB = Campaign::factory()->create(['user_id' => $this->userB->id]);

        $this->getJson("/api/v1/campaigns/{$campaignB->id}")->assertStatus(403);
    }

    public function test_user_cannot_update_another_users_campaign(): void
    {
        Sanctum::actingAs($this->userA);
        $campaignB = Campaign::factory()->create(['user_id' => $this->userB->id]);

        $this->putJson("/api/v1/campaigns/{$campaignB->id}", [
            'name' => 'Hacked Campaign Name',
        ])->assertStatus(403);
    }

    public function test_user_cannot_delete_another_users_campaign(): void
    {
        Sanctum::actingAs($this->userA);
        $campaignB = Campaign::factory()->create(['user_id' => $this->userB->id]);

        $this->deleteJson("/api/v1/campaigns/{$campaignB->id}")->assertStatus(403);
    }

    public function test_user_cannot_approve_dispatch_pause_resume_cancel_another_users_campaign(): void
    {
        Sanctum::actingAs($this->userA);
        $campaignB = Campaign::factory()->create(['user_id' => $this->userB->id]);

        $this->postJson("/api/v1/campaigns/{$campaignB->id}/approve")->assertStatus(403);
        $this->postJson("/api/v1/campaigns/{$campaignB->id}/dispatch")->assertStatus(403);
        $this->postJson("/api/v1/campaigns/{$campaignB->id}/pause")->assertStatus(403);
        $this->postJson("/api/v1/campaigns/{$campaignB->id}/resume")->assertStatus(403);
        $this->postJson("/api/v1/campaigns/{$campaignB->id}/cancel")->assertStatus(403);
    }

    /*
    |--------------------------------------------------------------------------
    | Campaign Creation Tests
    |--------------------------------------------------------------------------
    */

    public function test_valid_direct_campaign_creates_successfully(): void
    {
        Sanctum::actingAs($this->userA);

        $payload = [
            'name' => 'Direct Promo Email',
            'email_mode' => 'direct',
            'subject' => 'Exclusive Summer Offer',
            'email_body' => '<p>Get 20% off our products today!</p>',
        ];

        $response = $this->postJson('/api/v1/campaigns', $payload);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'message' => 'Campaign created successfully.',
                'data' => [
                    'name' => 'Direct Promo Email',
                    'email_mode' => 'direct',
                    'subject' => 'Exclusive Summer Offer',
                    'status' => 'draft',
                ],
            ]);

        $this->assertDatabaseHas('campaigns', [
            'user_id' => $this->userA->id,
            'name' => 'Direct Promo Email',
            'status' => CampaignStatus::DRAFT->value,
        ]);
    }

    public function test_valid_generate_campaign_creates_successfully(): void
    {
        Sanctum::actingAs($this->userA);

        $payload = [
            'name' => 'AI Generated Cold Outreach',
            'email_mode' => 'generate',
            'ai_prompt' => 'Write a compelling SaaS pitch for VP of Sales.',
            'ai_provider' => 'openai',
        ];

        $response = $this->postJson('/api/v1/campaigns', $payload);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'name' => 'AI Generated Cold Outreach',
                    'email_mode' => 'generate',
                    'ai_prompt' => 'Write a compelling SaaS pitch for VP of Sales.',
                    'status' => 'draft',
                ],
            ]);
    }

    public function test_valid_polish_campaign_creates_successfully(): void
    {
        Sanctum::actingAs($this->userA);

        $payload = [
            'name' => 'Rough Draft Polish Campaign',
            'email_mode' => 'polish',
            'raw_input_email' => 'hey we got discounts check it out',
            'ai_provider' => 'gemini',
        ];

        $response = $this->postJson('/api/v1/campaigns', $payload);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'name' => 'Rough Draft Polish Campaign',
                    'email_mode' => 'polish',
                    'raw_input_email' => 'hey we got discounts check it out',
                    'status' => 'draft',
                ],
            ]);
    }

    public function test_invalid_mode_and_required_fields_rejected(): void
    {
        Sanctum::actingAs($this->userA);

        // Invalid mode rejected
        $this->postJson('/api/v1/campaigns', [
            'name' => 'Bad Mode',
            'email_mode' => 'invalid_mode',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['email_mode']);

        // Direct mode missing subject and body
        $this->postJson('/api/v1/campaigns', [
            'name' => 'Missing Subject Body',
            'email_mode' => 'direct',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['subject', 'email_body']);

        // Generate mode missing prompt
        $this->postJson('/api/v1/campaigns', [
            'name' => 'Missing Prompt',
            'email_mode' => 'generate',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['ai_prompt']);

        // Polish mode missing rough content
        $this->postJson('/api/v1/campaigns', [
            'name' => 'Missing Polish Draft',
            'email_mode' => 'polish',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['raw_input_email']);
    }

    /*
    |--------------------------------------------------------------------------
    | Campaign Update and Delete Tests
    |--------------------------------------------------------------------------
    */

    public function test_owner_can_update_campaign_in_editable_status(): void
    {
        Sanctum::actingAs($this->userA);

        $campaign = Campaign::factory()->create([
            'user_id' => $this->userA->id,
            'status' => CampaignStatus::DRAFT,
            'name' => 'Old Name',
        ]);

        $response = $this->putJson("/api/v1/campaigns/{$campaign->id}", [
            'name' => 'Updated Name',
            'subject' => 'Updated Subject Line',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'name' => 'Updated Name',
                    'subject' => 'Updated Subject Line',
                ],
            ]);

        $this->assertDatabaseHas('campaigns', [
            'id' => $campaign->id,
            'name' => 'Updated Name',
        ]);
    }

    public function test_campaign_cannot_be_updated_in_non_editable_status(): void
    {
        Sanctum::actingAs($this->userA);

        $campaign = Campaign::factory()->create([
            'user_id' => $this->userA->id,
            'status' => CampaignStatus::SENDING,
            'name' => 'Sending Campaign',
        ]);

        $response = $this->putJson("/api/v1/campaigns/{$campaign->id}", [
            'name' => 'Attempted Edit',
        ]);

        $response->assertStatus(409)
            ->assertJson(['success' => false]);
    }

    public function test_owner_can_delete_draft_campaign(): void
    {
        Sanctum::actingAs($this->userA);

        $campaign = Campaign::factory()->create([
            'user_id' => $this->userA->id,
            'status' => CampaignStatus::DRAFT,
        ]);

        $response = $this->deleteJson("/api/v1/campaigns/{$campaign->id}");

        $response->assertStatus(200)->assertJson(['success' => true]);

        $this->assertSoftDeleted('campaigns', ['id' => $campaign->id]);
    }

    public function test_cannot_delete_actively_sending_or_queued_campaign(): void
    {
        Sanctum::actingAs($this->userA);

        $campaign = Campaign::factory()->create([
            'user_id' => $this->userA->id,
            'status' => CampaignStatus::SENDING,
        ]);

        $response = $this->deleteJson("/api/v1/campaigns/{$campaign->id}");

        $response->assertStatus(409)
            ->assertJson(['success' => false]);

        $this->assertDatabaseHas('campaigns', [
            'id' => $campaign->id,
            'deleted_at' => null,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Recipient Management Tests
    |--------------------------------------------------------------------------
    */

    public function test_owner_can_list_recipients(): void
    {
        Sanctum::actingAs($this->userA);

        $campaign = Campaign::factory()->create(['user_id' => $this->userA->id]);
        CampaignRecipient::factory()->count(4)->create(['campaign_id' => $campaign->id]);

        $response = $this->getJson("/api/v1/campaigns/{$campaign->id}/recipients");

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonCount(4, 'data');
    }

    public function test_non_owner_cannot_list_recipients(): void
    {
        Sanctum::actingAs($this->userB);

        $campaignA = Campaign::factory()->create(['user_id' => $this->userA->id]);

        $this->getJson("/api/v1/campaigns/{$campaignA->id}/recipients")->assertStatus(403);
    }

    public function test_valid_contacts_can_be_attached_as_recipients(): void
    {
        Sanctum::actingAs($this->userA);

        $campaign = Campaign::factory()->create([
            'user_id' => $this->userA->id,
            'status' => CampaignStatus::DRAFT,
            'subject' => 'Hello',
            'email_body' => 'Welcome',
        ]);

        $contacts = Contact::factory()->count(3)->create(['user_id' => $this->userA->id]);

        $response = $this->postJson("/api/v1/campaigns/{$campaign->id}/recipients", [
            'contact_ids' => $contacts->pluck('id')->all(),
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'campaign_id' => $campaign->id,
                    'total_recipients' => 3,
                ],
            ]);

        $this->assertDatabaseCount('campaign_recipients', 3);
    }

    public function test_cross_user_contact_cannot_be_attached(): void
    {
        Sanctum::actingAs($this->userA);

        $campaign = Campaign::factory()->create([
            'user_id' => $this->userA->id,
            'status' => CampaignStatus::DRAFT,
        ]);

        $contactB = Contact::factory()->create(['user_id' => $this->userB->id]);

        $response = $this->postJson("/api/v1/campaigns/{$campaign->id}/recipients", [
            'contact_ids' => [$contactB->id],
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseCount('campaign_recipients', 0);
    }

    public function test_duplicate_recipients_are_prevented(): void
    {
        Sanctum::actingAs($this->userA);

        $campaign = Campaign::factory()->create([
            'user_id' => $this->userA->id,
            'status' => CampaignStatus::DRAFT,
            'subject' => 'Hello',
            'email_body' => 'Welcome',
        ]);

        $contact = Contact::factory()->create(['user_id' => $this->userA->id]);

        // Attach first time
        $this->postJson("/api/v1/campaigns/{$campaign->id}/recipients", [
            'contact_ids' => [$contact->id],
        ])->assertStatus(200);

        // Attach second time with same contact
        $secondRes = $this->postJson("/api/v1/campaigns/{$campaign->id}/recipients", [
            'contact_ids' => [$contact->id],
        ]);

        $secondRes->assertStatus(200);
        $this->assertDatabaseCount('campaign_recipients', 1);
    }

    public function test_cannot_attach_recipients_after_sending_starts(): void
    {
        Sanctum::actingAs($this->userA);

        $campaign = Campaign::factory()->create([
            'user_id' => $this->userA->id,
            'status' => CampaignStatus::SENDING,
        ]);

        $contact = Contact::factory()->create(['user_id' => $this->userA->id]);

        $response = $this->postJson("/api/v1/campaigns/{$campaign->id}/recipients", [
            'contact_ids' => [$contact->id],
        ]);

        $response->assertStatus(409);
    }

    /*
    |--------------------------------------------------------------------------
    | AI Generation & Review Tests
    |--------------------------------------------------------------------------
    */

    public function test_generate_endpoint_queues_generation_and_returns_202(): void
    {
        Queue::fake([GenerateCampaignEmailJob::class]);
        Sanctum::actingAs($this->userA);

        $campaign = Campaign::factory()->create([
            'user_id' => $this->userA->id,
            'email_mode' => EmailMode::GENERATE,
            'status' => CampaignStatus::DRAFT,
            'ai_prompt' => 'Draft a product release email.',
        ]);

        $response = $this->postJson("/api/v1/campaigns/{$campaign->id}/generate", [
            'tone' => 'friendly',
            'target_audience' => 'Founders',
        ]);

        $response->assertStatus(202)
            ->assertJson([
                'success' => true,
                'data' => [
                    'campaign_id' => $campaign->id,
                    'status' => 'generating',
                ],
            ]);

        Queue::assertPushed(GenerateCampaignEmailJob::class, function ($job) use ($campaign) {
            return $job->campaignId === $campaign->id
                && ($job->options['tone'] ?? null) === 'friendly';
        });

        $this->assertSame(CampaignStatus::GENERATING, $campaign->fresh()->status);
    }

    public function test_duplicate_ai_generation_prevented_with_409(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->userA);

        $campaign = Campaign::factory()->create([
            'user_id' => $this->userA->id,
            'email_mode' => EmailMode::GENERATE,
            'status' => CampaignStatus::GENERATING,
        ]);

        $response = $this->postJson("/api/v1/campaigns/{$campaign->id}/generate");

        $response->assertStatus(409)
            ->assertJson(['success' => false]);

        Queue::assertNothingPushed();
    }

    public function test_direct_campaign_cannot_trigger_ai_generation(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->userA);

        $campaign = Campaign::factory()->create([
            'user_id' => $this->userA->id,
            'email_mode' => EmailMode::DIRECT,
            'status' => CampaignStatus::DRAFT,
            'subject' => 'Direct Mail',
            'email_body' => 'Direct Content',
        ]);

        $response = $this->postJson("/api/v1/campaigns/{$campaign->id}/generate");

        $response->assertStatus(422)
            ->assertJson(['success' => false]);

        Queue::assertNothingPushed();
    }

    public function test_review_api_exposes_generated_subject_and_body_without_secrets(): void
    {
        Sanctum::actingAs($this->userA);

        $campaign = Campaign::factory()->create([
            'user_id' => $this->userA->id,
            'email_mode' => EmailMode::GENERATE,
            'status' => CampaignStatus::REVIEW,
            'subject' => 'AI Generated Subject',
            'email_body' => '<p>AI Generated Body</p>',
            'email_body_text' => 'AI Generated Body',
        ]);

        $response = $this->getJson("/api/v1/campaigns/{$campaign->id}");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'id' => $campaign->id,
                    'subject' => 'AI Generated Subject',
                    'email_body' => '<p>AI Generated Body</p>',
                    'status' => 'review',
                    'is_review_required' => true,
                ],
            ]);

        $jsonString = $response->getContent() ?: '';
        $this->assertStringNotContainsString('api_key', $jsonString);
        $this->assertStringNotContainsString('secret', $jsonString);
        $this->assertStringNotContainsString('password', $jsonString);
    }

    /*
    |--------------------------------------------------------------------------
    | Critical Safety Rule Security Test
    |--------------------------------------------------------------------------
    */

    public function test_ai_generation_cannot_automatically_send_email(): void
    {
        Queue::fake();
        Mail::fake();

        Sanctum::actingAs($this->userA);

        $campaign = Campaign::factory()->create([
            'user_id' => $this->userA->id,
            'email_mode' => EmailMode::GENERATE,
            'status' => CampaignStatus::DRAFT,
            'ai_prompt' => 'Write a promotional email for new shoes.',
        ]);

        $response = $this->postJson("/api/v1/campaigns/{$campaign->id}/generate");

        $response->assertStatus(202);

        // 1. Assert GenerateCampaignEmailJob was queued
        Queue::assertPushed(GenerateCampaignEmailJob::class);

        // 2. Assert SendCampaignEmailJob was NOT queued
        Queue::assertNotPushed(SendCampaignEmailJob::class);

        // 3. Assert no mail was sent
        Mail::assertNothingSent();

        // 4. Assert campaign is not approved
        $freshCampaign = $campaign->fresh();
        $this->assertNotSame(CampaignStatus::APPROVED, $freshCampaign->status);
        $this->assertNull($freshCampaign->approved_at);

        // 5. Assert campaign is not sending
        $this->assertNotSame(CampaignStatus::SENDING, $freshCampaign->status);
        $this->assertNull($freshCampaign->started_at);
    }

    /*
    |--------------------------------------------------------------------------
    | Approval & Dispatch Gate Tests
    |--------------------------------------------------------------------------
    */

    public function test_valid_campaign_can_be_approved(): void
    {
        Sanctum::actingAs($this->userA);

        $campaign = Campaign::factory()->create([
            'user_id' => $this->userA->id,
            'status' => CampaignStatus::REVIEW,
            'subject' => 'Approved Subject',
            'email_body' => '<p>Approved Body</p>',
        ]);

        $response = $this->postJson("/api/v1/campaigns/{$campaign->id}/approve");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'status' => 'approved',
                ],
            ]);

        $fresh = $campaign->fresh();
        $this->assertSame(CampaignStatus::APPROVED, $fresh->status);
        $this->assertNotNull($fresh->approved_at);
    }

    public function test_approval_does_not_send_email(): void
    {
        Queue::fake();
        Mail::fake();
        Sanctum::actingAs($this->userA);

        $campaign = Campaign::factory()->create([
            'user_id' => $this->userA->id,
            'status' => CampaignStatus::REVIEW,
            'subject' => 'Review Ready',
            'email_body' => '<p>Ready</p>',
        ]);

        $response = $this->postJson("/api/v1/campaigns/{$campaign->id}/approve");

        $response->assertStatus(200);

        Queue::assertNotPushed(SendCampaignEmailJob::class);
        Mail::assertNothingSent();
    }

    public function test_cannot_approve_campaign_without_subject_or_body(): void
    {
        Sanctum::actingAs($this->userA);

        $campaign = Campaign::factory()->create([
            'user_id' => $this->userA->id,
            'status' => CampaignStatus::REVIEW,
            'subject' => null,
            'email_body' => null,
        ]);

        $this->postJson("/api/v1/campaigns/{$campaign->id}/approve")
            ->assertStatus(422);
    }

    public function test_already_approved_campaign_cannot_be_approved_again(): void
    {
        Sanctum::actingAs($this->userA);

        $campaign = Campaign::factory()->create([
            'user_id' => $this->userA->id,
            'status' => CampaignStatus::APPROVED,
            'subject' => 'Already Approved',
            'email_body' => '<p>Ready</p>',
        ]);

        $this->postJson("/api/v1/campaigns/{$campaign->id}/approve")
            ->assertStatus(409);
    }

    public function test_approved_campaign_dispatch_returns_202_and_queues_sending(): void
    {
        Queue::fake([SendCampaignEmailJob::class]);
        Sanctum::actingAs($this->userA);

        $campaign = Campaign::factory()->create([
            'user_id' => $this->userA->id,
            'status' => CampaignStatus::APPROVED,
            'subject' => 'Dispatchable',
            'email_body' => '<p>Content</p>',
        ]);

        $contact = Contact::factory()->create(['user_id' => $this->userA->id]);
        app(CreateCampaignRecipientsAction::class)->execute($campaign, [$contact->id]);

        $response = $this->postJson("/api/v1/campaigns/{$campaign->id}/dispatch");

        $response->assertStatus(202)
            ->assertJson([
                'success' => true,
                'data' => [
                    'campaign_id' => $campaign->id,
                    'status' => 'sending',
                    'enqueued_recipients' => 1,
                ],
            ]);

        Queue::assertPushed(SendCampaignEmailJob::class);
        $this->assertSame(CampaignStatus::SENDING, $campaign->fresh()->status);
    }

    public function test_dispatch_cannot_bypass_approval(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->userA);

        $unapprovedStatuses = [
            CampaignStatus::DRAFT,
            CampaignStatus::GENERATING,
            CampaignStatus::REVIEW,
            CampaignStatus::SENDING,
            CampaignStatus::COMPLETED,
            CampaignStatus::CANCELLED,
        ];

        foreach ($unapprovedStatuses as $status) {
            $campaign = Campaign::factory()->create([
                'user_id' => $this->userA->id,
                'status' => $status,
                'subject' => 'Not Approved Yet',
                'email_body' => '<p>Content</p>',
            ]);

            $response = $this->postJson("/api/v1/campaigns/{$campaign->id}/dispatch");
            $response->assertStatus(409);
        }

        Queue::assertNothingPushed();
    }

    public function test_dispatch_fails_if_no_recipients_attached(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->userA);

        $campaign = Campaign::factory()->create([
            'user_id' => $this->userA->id,
            'status' => CampaignStatus::APPROVED,
            'subject' => 'Approved but empty recipients',
            'email_body' => '<p>Empty</p>',
        ]);

        $response = $this->postJson("/api/v1/campaigns/{$campaign->id}/dispatch");

        $response->assertStatus(409);
        Queue::assertNothingPushed();
    }

    /*
    |--------------------------------------------------------------------------
    | Pause, Resume, and Cancel Tests
    |--------------------------------------------------------------------------
    */

    public function test_sending_campaign_can_pause(): void
    {
        Sanctum::actingAs($this->userA);

        $campaign = Campaign::factory()->create([
            'user_id' => $this->userA->id,
            'status' => CampaignStatus::SENDING,
        ]);

        $response = $this->postJson("/api/v1/campaigns/{$campaign->id}/pause");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'status' => 'paused',
                ],
            ]);

        $this->assertSame(CampaignStatus::PAUSED, $campaign->fresh()->status);
    }

    public function test_paused_campaign_can_resume(): void
    {
        Queue::fake([SendCampaignEmailJob::class]);
        Sanctum::actingAs($this->userA);

        $campaign = Campaign::factory()->create([
            'user_id' => $this->userA->id,
            'status' => CampaignStatus::PAUSED,
        ]);

        CampaignRecipient::factory()->create([
            'campaign_id' => $campaign->id,
            'status' => RecipientDeliveryStatus::QUEUED,
        ]);

        $response = $this->postJson("/api/v1/campaigns/{$campaign->id}/resume");

        $response->assertStatus(202)
            ->assertJson([
                'success' => true,
                'data' => [
                    'status' => 'sending',
                ],
            ]);

        Queue::assertPushed(SendCampaignEmailJob::class);
        $this->assertSame(CampaignStatus::SENDING, $campaign->fresh()->status);
    }

    public function test_cancellable_campaign_can_cancel(): void
    {
        Sanctum::actingAs($this->userA);

        $campaign = Campaign::factory()->create([
            'user_id' => $this->userA->id,
            'status' => CampaignStatus::SENDING,
        ]);

        $sentRecipient = CampaignRecipient::factory()->create([
            'campaign_id' => $campaign->id,
            'status' => RecipientDeliveryStatus::SENT,
        ]);

        $queuedRecipient = CampaignRecipient::factory()->create([
            'campaign_id' => $campaign->id,
            'status' => RecipientDeliveryStatus::QUEUED,
        ]);

        $response = $this->postJson("/api/v1/campaigns/{$campaign->id}/cancel");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'status' => 'cancelled',
                ],
            ]);

        $this->assertSame(CampaignStatus::CANCELLED, $campaign->fresh()->status);
        $this->assertSame(RecipientDeliveryStatus::SENT, $sentRecipient->fresh()->status);
        $this->assertSame(RecipientDeliveryStatus::CANCELLED, $queuedRecipient->fresh()->status);
    }

    /*
    |--------------------------------------------------------------------------
    | Status and Delivery Status Reporting Tests
    |--------------------------------------------------------------------------
    */

    public function test_status_endpoint_returns_real_counts_and_timestamps(): void
    {
        Sanctum::actingAs($this->userA);

        $campaign = Campaign::factory()->create([
            'user_id' => $this->userA->id,
            'status' => CampaignStatus::SENDING,
            'email_mode' => EmailMode::DIRECT,
            'total_recipients' => 3,
        ]);

        CampaignRecipient::factory()->create([
            'campaign_id' => $campaign->id,
            'status' => RecipientDeliveryStatus::SENT,
        ]);

        CampaignRecipient::factory()->create([
            'campaign_id' => $campaign->id,
            'status' => RecipientDeliveryStatus::FAILED,
        ]);

        CampaignRecipient::factory()->create([
            'campaign_id' => $campaign->id,
            'status' => RecipientDeliveryStatus::QUEUED,
        ]);

        $response = $this->getJson("/api/v1/campaigns/{$campaign->id}/status");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'campaign_id' => $campaign->id,
                    'campaign_status' => 'sending',
                    'email_mode' => 'direct',
                    'total_recipients' => 3,
                    'sent_count' => 1,
                    'failed_count' => 1,
                    'queued_count' => 1,
                    'cancelled_count' => 0,
                    'delivery_progress' => 66.67,
                ],
            ]);
    }

    public function test_delivery_status_endpoint_returns_real_aggregate_counts(): void
    {
        Sanctum::actingAs($this->userA);

        $campaign = Campaign::factory()->create([
            'user_id' => $this->userA->id,
            'status' => CampaignStatus::SENDING,
        ]);

        CampaignRecipient::factory()->create([
            'campaign_id' => $campaign->id,
            'status' => RecipientDeliveryStatus::SENT,
        ]);

        CampaignRecipient::factory()->create([
            'campaign_id' => $campaign->id,
            'status' => RecipientDeliveryStatus::QUEUED,
        ]);

        CampaignRecipient::factory()->create([
            'campaign_id' => $campaign->id,
            'status' => RecipientDeliveryStatus::FAILED,
        ]);

        $response = $this->getJson("/api/v1/campaigns/{$campaign->id}/delivery-status");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'campaign_id' => $campaign->id,
                    'total' => 3,
                    'sent' => 1,
                    'queued' => 1,
                    'failed' => 1,
                    'processing' => 0,
                    'cancelled' => 0,
                ],
            ]);
    }
}
