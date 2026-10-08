<?php

declare(strict_types=1);

namespace Tests\Feature\Business;

use App\Models\User;
use App\Products\MailForge\Actions\Campaign\ApproveCampaignAction;
use App\Products\MailForge\Actions\Campaign\CancelCampaignAction;
use App\Products\MailForge\Actions\Campaign\CreateCampaignAction;
use App\Products\MailForge\Actions\Campaign\CreateCampaignRecipientsAction;
use App\Products\MailForge\Actions\Campaign\DispatchCampaignSendingAction;
use App\Products\MailForge\Actions\Campaign\PauseCampaignAction;
use App\Products\MailForge\Actions\Campaign\ProcessCampaignAiContentAction;
use App\Products\MailForge\Enums\AiProviderType;
use App\Products\MailForge\Enums\CampaignStatus;
use App\Products\MailForge\Enums\EmailMode;
use App\Products\MailForge\Enums\RecipientDeliveryStatus;
use App\Products\MailForge\Events\CampaignApproved;
use App\Products\MailForge\Events\CampaignDispatched;
use App\Products\MailForge\Jobs\GenerateCampaignEmailJob;
use App\Products\MailForge\Jobs\SendCampaignEmailJob;
use App\Products\MailForge\Models\Campaign;
use App\Products\MailForge\Models\Contact;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Tests\TestCase;

class CampaignLifecycleWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('mailforge.ai.default_provider', 'mock');
    }

    public function test_create_campaign_action_validates_modes(): void
    {
        $user = User::factory()->create();
        $action = new CreateCampaignAction;

        // 1. Direct mode without body should fail
        $this->expectException(InvalidArgumentException::class);
        $action->execute($user->id, [
            'name' => 'Direct Campaign',
            'email_mode' => 'direct',
            'subject' => 'Hello',
            'email_body' => '',
        ]);
    }

    public function test_create_campaign_action_succeeds_for_generate_mode(): void
    {
        $user = User::factory()->create();
        $action = new CreateCampaignAction;

        $campaign = $action->execute($user->id, [
            'name' => 'AI Outreach',
            'email_mode' => 'generate',
            'ai_prompt' => 'Introduce our new software solution to marketing leaders',
        ]);

        $this->assertSame(CampaignStatus::DRAFT, $campaign->status);
        $this->assertSame(EmailMode::GENERATE, $campaign->email_mode);
        $this->assertSame('Introduce our new software solution to marketing leaders', $campaign->ai_prompt);
    }

    public function test_create_campaign_recipients_action_attaches_and_prevents_duplicates(): void
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->create(['user_id' => $user->id]);

        $contact1 = Contact::factory()->create(['user_id' => $user->id]);
        $contact2 = Contact::factory()->create(['user_id' => $user->id]);

        $action = new CreateCampaignRecipientsAction;

        // Attach contacts
        $count = $action->execute($campaign, [$contact1->id, $contact2->id]);
        $this->assertSame(2, $count);
        $this->assertSame(2, $campaign->fresh()->total_recipients);

        // Attempting to attach again should safely avoid duplicate recipient entries
        $countAfter = $action->execute($campaign, [$contact1->id, $contact2->id]);
        $this->assertSame(2, $countAfter);
        $this->assertDatabaseCount('campaign_recipients', 2);
    }

    public function test_process_campaign_ai_content_action_generates_copy_and_moves_to_review(): void
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->create([
            'user_id' => $user->id,
            'email_mode' => EmailMode::GENERATE,
            'ai_provider' => AiProviderType::MOCK,
            'ai_prompt' => 'Write a cold pitch for B2B procurement heads',
            'status' => CampaignStatus::DRAFT,
        ]);

        $action = app(ProcessCampaignAiContentAction::class);
        $action->execute($campaign, ['tone' => 'persuasive']);

        $fresh = $campaign->fresh();
        $this->assertSame(CampaignStatus::REVIEW, $fresh->status);
        $this->assertNotNull($fresh->subject);
        $this->assertNotNull($fresh->email_body);

        // Verify audit log
        $this->assertDatabaseHas('ai_generation_logs', [
            'campaign_id' => $campaign->id,
            'user_id' => $user->id,
            'provider' => 'mock',
            'status' => 'success',
        ]);
    }

    public function test_generate_campaign_email_job_runs_job_queue_workflow(): void
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->create([
            'user_id' => $user->id,
            'email_mode' => EmailMode::POLISH,
            'ai_provider' => AiProviderType::MOCK,
            'raw_input_email' => 'Hey check our cool product thanks',
            'status' => CampaignStatus::DRAFT,
        ]);

        $job = new GenerateCampaignEmailJob($campaign->id);
        $job->handle(app(ProcessCampaignAiContentAction::class));

        $this->assertSame(CampaignStatus::REVIEW, $campaign->fresh()->status);
        $this->assertStringContainsString('Polished Outreach', $campaign->fresh()->subject);
    }

    public function test_approval_gate_and_dispatch_workflow(): void
    {
        Event::fake([CampaignApproved::class, CampaignDispatched::class]);
        Queue::fake([SendCampaignEmailJob::class]);

        $user = User::factory()->create();
        $contact = Contact::factory()->create(['user_id' => $user->id]);

        $campaign = Campaign::factory()->create([
            'user_id' => $user->id,
            'subject' => 'Approved Subject',
            'email_body' => '<p>Approved Body</p>',
            'status' => CampaignStatus::REVIEW,
        ]);

        // Attach recipient
        (new CreateCampaignRecipientsAction)->execute($campaign, [$contact->id]);

        // 1. Approve
        $approveAction = new ApproveCampaignAction;
        $approvedCampaign = $approveAction->execute($campaign);

        $this->assertSame(CampaignStatus::APPROVED, $approvedCampaign->status);
        $this->assertNotNull($approvedCampaign->approved_at);
        Event::assertDispatched(CampaignApproved::class);

        // 2. Dispatch
        $dispatchAction = new DispatchCampaignSendingAction;
        $dispatchedCount = $dispatchAction->execute($approvedCampaign);

        $this->assertSame(1, $dispatchedCount);
        $this->assertSame(CampaignStatus::SENDING, $campaign->fresh()->status);
        $this->assertNotNull($campaign->fresh()->started_at);

        // Verify recipient status set to QUEUED
        $this->assertDatabaseHas('campaign_recipients', [
            'campaign_id' => $campaign->id,
            'status' => RecipientDeliveryStatus::QUEUED->value,
        ]);

        Event::assertDispatched(CampaignDispatched::class);
        Queue::assertPushed(SendCampaignEmailJob::class, 1);
    }

    public function test_cannot_dispatch_unapproved_campaign(): void
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->create([
            'user_id' => $user->id,
            'status' => CampaignStatus::DRAFT,
        ]);

        $dispatchAction = new DispatchCampaignSendingAction;

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Only approved campaigns can be dispatched');

        $dispatchAction->execute($campaign);
    }

    public function test_pause_and_cancel_actions(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->create(['user_id' => $user->id]);

        $campaign = Campaign::factory()->create([
            'user_id' => $user->id,
            'status' => CampaignStatus::SENDING,
        ]);

        (new CreateCampaignRecipientsAction)->execute($campaign, [$contact->id]);

        // 1. Pause
        $pauseAction = new PauseCampaignAction;
        $paused = $pauseAction->execute($campaign);
        $this->assertSame(CampaignStatus::PAUSED, $paused->status);

        // 2. Cancel
        $cancelAction = new CancelCampaignAction;
        $cancelled = $cancelAction->execute($paused);
        $this->assertSame(CampaignStatus::CANCELLED, $cancelled->status);

        // Recipient cancelled
        $this->assertDatabaseHas('campaign_recipients', [
            'campaign_id' => $campaign->id,
            'status' => RecipientDeliveryStatus::CANCELLED->value,
        ]);
    }
}
