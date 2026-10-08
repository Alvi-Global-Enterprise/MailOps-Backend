<?php

declare(strict_types=1);

namespace Tests\Feature\Integration;

use App\Models\User;
use App\Products\MailForge\Actions\Campaign\ProcessCampaignAiContentAction;
use App\Products\MailForge\Enums\CampaignStatus;
use App\Products\MailForge\Enums\RecipientDeliveryStatus;
use App\Products\MailForge\Jobs\GenerateCampaignEmailJob;
use App\Products\MailForge\Jobs\SendCampaignEmailJob;
use App\Products\MailForge\Models\Campaign;
use App\Products\MailForge\Models\CampaignRecipient;
use App\Products\MailForge\Services\Delivery\EmailDeliveryManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CampaignEndToEndWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_complete_end_to_end_campaign_lifecycle_with_mock_providers(): void
    {
        // 1. Configure deterministic mocks
        Config::set('mailforge.ai.default_provider', 'mock');
        Config::set('mailforge.delivery.default_mailer', 'mock');

        // 2. Authenticate User
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        // 3. Create Contact via REST API
        $contactResponse = $this->postJson('/api/v1/contacts', [
            'email' => 'jane.doe@enterprise.io',
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'company' => 'Enterprise IO',
            'job_title' => 'VP of Engineering',
        ]);

        $contactResponse->assertStatus(201);
        $contactId = $contactResponse->json('data.id');
        $this->assertNotNull($contactId);

        // 4. Create Campaign in GENERATE mode via REST API
        $campaignResponse = $this->postJson('/api/v1/campaigns', [
            'name' => 'Enterprise AI Solutions Outreach',
            'email_mode' => 'generate',
            'ai_prompt' => 'Write a persuasive email introducing our AI workflow automation.',
            'ai_provider' => 'mock',
        ]);

        $campaignResponse->assertStatus(201);
        $campaignId = $campaignResponse->json('data.id');
        $this->assertNotNull($campaignId);

        // 5. Attach Recipient via REST API
        $recipientsResponse = $this->postJson("/api/v1/campaigns/{$campaignId}/recipients", [
            'contact_ids' => [$contactId],
        ]);

        $recipientsResponse->assertStatus(200)
            ->assertJsonPath('data.total_recipients', 1);

        $recipient = CampaignRecipient::where('campaign_id', $campaignId)->firstOrFail();
        $this->assertSame(RecipientDeliveryStatus::DRAFT, $recipient->status);

        // 6. Trigger AI Generation via REST API
        Queue::fake([GenerateCampaignEmailJob::class, SendCampaignEmailJob::class]);

        $generateResponse = $this->postJson("/api/v1/campaigns/{$campaignId}/generate", [
            'tone' => 'professional',
            'target_audience' => 'VP of Engineering',
        ]);

        $generateResponse->assertStatus(202)
            ->assertJsonPath('data.status', 'generating');

        Queue::assertPushed(GenerateCampaignEmailJob::class);
        Queue::assertNotPushed(SendCampaignEmailJob::class);

        // 7. Simulate background AI generation job execution
        $campaign = Campaign::findOrFail($campaignId);
        $processAction = app(ProcessCampaignAiContentAction::class);
        $processAction->execute($campaign, ['tone' => 'professional']);

        $campaign->refresh();

        // 8. Verify campaign is in REVIEW state and AI generation log exists
        $this->assertSame(CampaignStatus::REVIEW, $campaign->status);
        $this->assertNotEmpty($campaign->subject);
        $this->assertNotEmpty($campaign->email_body);

        $this->assertDatabaseHas('ai_generation_logs', [
            'campaign_id' => $campaignId,
            'user_id' => $user->id,
            'provider' => 'mock',
            'status' => 'success',
        ]);

        // 9. Verify Campaign Review API
        $showResponse = $this->getJson("/api/v1/campaigns/{$campaignId}");
        $showResponse->assertStatus(200)
            ->assertJsonPath('data.is_review_required', true)
            ->assertJsonPath('data.status', 'review');

        // 10. Human Approval Gate
        $approveResponse = $this->postJson("/api/v1/campaigns/{$campaignId}/approve");
        $approveResponse->assertStatus(200)
            ->assertJsonPath('data.status', 'approved');

        $campaign->refresh();
        $this->assertSame(CampaignStatus::APPROVED, $campaign->status);
        $this->assertNotNull($campaign->approved_at);

        // 11. Dispatch Campaign via REST API
        $dispatchResponse = $this->postJson("/api/v1/campaigns/{$campaignId}/dispatch");
        $dispatchResponse->assertStatus(202)
            ->assertJsonPath('data.status', 'sending')
            ->assertJsonPath('data.enqueued_recipients', 1);

        Queue::assertPushed(SendCampaignEmailJob::class);

        $recipient->refresh();
        $this->assertSame(RecipientDeliveryStatus::QUEUED, $recipient->status);

        // 12. Simulate Worker Processing of SendCampaignEmailJob
        $sendJob = new SendCampaignEmailJob($recipient->id);
        $deliveryManager = app(EmailDeliveryManager::class);
        $sendJob->handle($deliveryManager);

        $recipient->refresh();
        $campaign->refresh();

        // 13. Verify successful transmission and audit log
        $this->assertSame(RecipientDeliveryStatus::SENT, $recipient->status);
        $this->assertNotNull($recipient->sent_at);
        $this->assertSame(1, $recipient->attempts);

        $this->assertDatabaseHas('email_delivery_logs', [
            'campaign_id' => $campaignId,
            'campaign_recipient_id' => $recipient->id,
            'provider' => 'mock',
            'status' => 'sent',
            'response_code' => '250',
        ]);

        // 14. Verify campaign automatically reached COMPLETED state
        $this->assertSame(CampaignStatus::COMPLETED, $campaign->status);
        $this->assertNotNull($campaign->completed_at);
        $this->assertSame(1, $campaign->sent_count);
        $this->assertSame(0, $campaign->failed_count);

        // 15. Verify status and delivery-status REST endpoints
        $statusResponse = $this->getJson("/api/v1/campaigns/{$campaignId}/status");
        $statusResponse->assertStatus(200)
            ->assertJsonPath('data.campaign_status', 'completed')
            ->assertJsonPath('data.sent_count', 1)
            ->assertJsonPath('data.delivery_progress', 100);

        $deliveryStatusResponse = $this->getJson("/api/v1/campaigns/{$campaignId}/delivery-status");
        $deliveryStatusResponse->assertStatus(200)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.sent', 1)
            ->assertJsonPath('data.queued', 0)
            ->assertJsonPath('data.failed', 0);
    }
}
