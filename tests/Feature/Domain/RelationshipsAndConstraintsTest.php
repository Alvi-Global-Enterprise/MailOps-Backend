<?php

declare(strict_types=1);

namespace Tests\Feature\Domain;

use App\Models\User;
use App\Products\MailForge\Enums\CampaignStatus;
use App\Products\MailForge\Enums\ContactSourceType;
use App\Products\MailForge\Enums\EmailMode;
use App\Products\MailForge\Models\AiGenerationLog;
use App\Products\MailForge\Models\Campaign;
use App\Products\MailForge\Models\CampaignRecipient;
use App\Products\MailForge\Models\Contact;
use App\Products\MailForge\Models\EmailDeliveryLog;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RelationshipsAndConstraintsTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_relationships_work(): void
    {
        $user = User::factory()->create();

        $contact = Contact::factory()->create(['user_id' => $user->id]);
        $campaign = Campaign::factory()->create(['user_id' => $user->id]);
        $aiLog = AiGenerationLog::factory()->create([
            'user_id' => $user->id,
            'campaign_id' => $campaign->id,
        ]);

        $this->assertCount(1, $user->contacts);
        $this->assertCount(1, $user->campaigns);
        $this->assertCount(1, $user->aiGenerationLogs);

        $this->assertTrue($user->contacts->first()->is($contact));
        $this->assertTrue($user->campaigns->first()->is($campaign));
        $this->assertTrue($user->aiGenerationLogs->first()->is($aiLog));
    }

    public function test_campaign_recipient_and_delivery_logs_relationships_work(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->create(['user_id' => $user->id]);
        $campaign = Campaign::factory()->create(['user_id' => $user->id]);

        $recipient = CampaignRecipient::factory()->create([
            'campaign_id' => $campaign->id,
            'contact_id' => $contact->id,
            'email' => $contact->email,
        ]);

        $deliveryLog = EmailDeliveryLog::factory()->create([
            'campaign_recipient_id' => $recipient->id,
            'campaign_id' => $campaign->id,
        ]);

        $this->assertCount(1, $campaign->recipients);
        $this->assertCount(1, $campaign->contacts);
        $this->assertCount(1, $campaign->deliveryLogs);
        $this->assertTrue($campaign->contacts->first()->is($contact));

        $this->assertCount(1, $contact->campaignRecipients);
        $this->assertCount(1, $contact->campaigns);
        $this->assertTrue($contact->campaigns->first()->is($campaign));

        $this->assertCount(1, $recipient->deliveryLogs);
        $this->assertTrue($recipient->deliveryLogs->first()->is($deliveryLog));
        $this->assertTrue($recipient->campaign->is($campaign));
        $this->assertTrue($recipient->contact->is($contact));
    }

    public function test_unique_constraint_on_contact_email_per_user(): void
    {
        $user = User::factory()->create();

        Contact::factory()->create([
            'user_id' => $user->id,
            'email' => 'duplicate@example.com',
        ]);

        $this->expectException(QueryException::class);

        // Attempting to create duplicate email for the same user should throw a unique violation
        Contact::factory()->create([
            'user_id' => $user->id,
            'email' => 'duplicate@example.com',
        ]);
    }

    public function test_different_users_can_have_same_contact_email(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $c1 = Contact::factory()->create([
            'user_id' => $user1->id,
            'email' => 'shared@example.com',
        ]);

        $c2 = Contact::factory()->create([
            'user_id' => $user2->id,
            'email' => 'shared@example.com',
        ]);

        $this->assertNotNull($c1->id);
        $this->assertNotNull($c2->id);
        $this->assertNotSame($c1->id, $c2->id);
    }

    public function test_unique_constraint_on_campaign_recipient_pair(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->create(['user_id' => $user->id]);
        $campaign = Campaign::factory()->create(['user_id' => $user->id]);

        CampaignRecipient::factory()->create([
            'campaign_id' => $campaign->id,
            'contact_id' => $contact->id,
        ]);

        $this->expectException(QueryException::class);

        // Attempting to attach the same contact twice to the same campaign should fail
        CampaignRecipient::factory()->create([
            'campaign_id' => $campaign->id,
            'contact_id' => $contact->id,
        ]);
    }

    public function test_custom_data_jsonb_and_enum_casting_work_seamlessly(): void
    {
        $user = User::factory()->create();

        $contact = Contact::create([
            'user_id' => $user->id,
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'JOHN.DOE@EXAMPLE.COM',
            'custom_data' => [
                'sheets_row_id' => 42,
                'utm_campaign' => 'q4_enterprise',
                'tags' => ['vip', 'saas_buyer'],
            ],
            'source' => ContactSourceType::GOOGLE_SHEETS,
        ]);

        // Email normalization to lowercase
        $this->assertSame('john.doe@example.com', $contact->email);
        $this->assertSame('John Doe', $contact->full_name);

        // Custom data array/JSON roundtrip
        $freshContact = Contact::findOrFail($contact->id);
        $this->assertIsArray($freshContact->custom_data);
        $this->assertSame(42, $freshContact->custom_data['sheets_row_id']);
        $this->assertSame(['vip', 'saas_buyer'], $freshContact->custom_data['tags']);
        $this->assertSame(ContactSourceType::GOOGLE_SHEETS, $freshContact->source);
    }

    public function test_campaign_lifecycle_and_enum_casts(): void
    {
        $user = User::factory()->create();

        $campaign = Campaign::create([
            'user_id' => $user->id,
            'name' => 'Test Outreach',
            'status' => CampaignStatus::DRAFT,
            'email_mode' => EmailMode::POLISH,
        ]);

        $this->assertSame(CampaignStatus::DRAFT, $campaign->status);
        $this->assertSame(EmailMode::POLISH, $campaign->email_mode);

        $campaign->status = CampaignStatus::REVIEW;
        $campaign->save();

        $this->assertSame(CampaignStatus::REVIEW, $campaign->fresh()->status);
        $this->assertTrue($campaign->fresh()->status->canApprove());
    }
}
