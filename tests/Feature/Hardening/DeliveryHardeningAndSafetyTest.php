<?php

declare(strict_types=1);

namespace Tests\Feature\Hardening;

use App\Models\User;
use App\Products\MailForge\Actions\Campaign\DispatchCampaignSendingAction;
use App\Products\MailForge\Actions\Campaign\ProcessCampaignAiContentAction;
use App\Products\MailForge\Actions\Campaign\RecoverStaleProcessingRecipientsAction;
use App\Products\MailForge\Actions\Campaign\ResumeCampaignAction;
use App\Products\MailForge\Actions\Contact\ImportContactsFromExcelAction;
use App\Products\MailForge\Enums\CampaignStatus;
use App\Products\MailForge\Enums\EmailMode;
use App\Products\MailForge\Enums\RecipientDeliveryStatus;
use App\Products\MailForge\Events\CampaignCompleted;
use App\Products\MailForge\Events\EmailDeliveryFailed;
use App\Products\MailForge\Events\EmailSent;
use App\Products\MailForge\Jobs\GenerateCampaignEmailJob;
use App\Products\MailForge\Jobs\SendCampaignEmailJob;
use App\Products\MailForge\Models\Campaign;
use App\Products\MailForge\Models\CampaignRecipient;
use App\Products\MailForge\Models\Contact;
use App\Products\MailForge\Services\Delivery\EmailDeliveryManager;
use App\Products\MailForge\Services\Delivery\Providers\MockEmailDeliveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DeliveryHardeningAndSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Campaign $campaign;

    protected Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $this->campaign = Campaign::factory()->create([
            'user_id' => $this->user->id,
            'status' => CampaignStatus::APPROVED,
            'subject' => 'Hardening Subject for {{first_name}}',
            'email_body' => '<p>Hello {{first_name}}</p>',
            'email_body_text' => 'Hello {{first_name}}',
            'total_recipients' => 1,
            'sent_count' => 0,
            'failed_count' => 0,
        ]);

        $this->contact = Contact::factory()->create([
            'user_id' => $this->user->id,
            'email' => 'hardened@example.com',
            'first_name' => 'Grace',
        ]);
    }

    /**
     * Scenario A & B: Duplicate execution and concurrent worker claim protection.
     */
    public function test_scenario_a_and_b_duplicate_job_and_concurrent_workers_send_only_once(): void
    {
        Event::fake([EmailSent::class]);

        $recipient = CampaignRecipient::factory()->create([
            'campaign_id' => $this->campaign->id,
            'contact_id' => $this->contact->id,
            'email' => 'hardened@example.com',
            'status' => RecipientDeliveryStatus::QUEUED,
        ]);

        $mockDelivery = new MockEmailDeliveryService;
        $deliveryManager = new EmailDeliveryManager($this->app);
        $deliveryManager->extend('mock', fn () => $mockDelivery);
        config(['mailforge.delivery.default_mailer' => 'mock']);

        // Worker 1 runs
        $job1 = new SendCampaignEmailJob($recipient->id);
        $job1->handle($deliveryManager);

        $recipient->refresh();
        $this->assertEquals(RecipientDeliveryStatus::SENT, $recipient->status);
        $this->assertEquals(1, $this->campaign->fresh()->sent_count);
        $this->assertCount(1, $mockDelivery->getSentMessages());

        // Worker 2 (or duplicate replayed execution) runs for same recipient
        $job2 = new SendCampaignEmailJob($recipient->id);
        $job2->handle($deliveryManager);

        // Verification: Worker 2 skipped, mock still has only 1 message sent
        $this->assertCount(1, $mockDelivery->getSentMessages());
        $this->assertEquals(1, $this->campaign->fresh()->sent_count);
        Event::assertDispatched(EmailSent::class, 1);
    }

    /**
     * Scenario C & E: Transient delivery failure and subsequent successful retry.
     */
    public function test_scenario_c_and_e_transient_failure_resets_to_queued_and_succeeds_on_retry(): void
    {
        $recipient = CampaignRecipient::factory()->create([
            'campaign_id' => $this->campaign->id,
            'contact_id' => $this->contact->id,
            'email' => 'transient@example.com',
            'status' => RecipientDeliveryStatus::QUEUED,
        ]);

        $mockDelivery = new MockEmailDeliveryService;
        // Configure mock to fail with temporary SMTP 421 on attempt 1
        $mockDelivery->simulateFailure(true, '421 Service temporarily unavailable', '421');

        $deliveryManager = new EmailDeliveryManager($this->app);
        $deliveryManager->extend('mock', fn () => $mockDelivery);
        config(['mailforge.delivery.default_mailer' => 'mock']);

        $job = new SendCampaignEmailJob($recipient->id);

        // First attempt throws transient exception for Laravel queue backoff
        try {
            $job->handle($deliveryManager);
            $this->fail('Expected transient RuntimeException was not thrown.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Transient email delivery failure', $e->getMessage());
        }

        $recipient->refresh();
        // Crucial: status must be QUEUED and processing_started_at null so retry job can claim it
        $this->assertEquals(RecipientDeliveryStatus::QUEUED, $recipient->status);
        $this->assertNull($recipient->processing_started_at);
        $this->assertEquals(1, $recipient->attempts);
        $this->assertEquals(0, $this->campaign->fresh()->failed_count);

        // Verify delivery log recorded attempt 1
        $this->assertDatabaseHas('email_delivery_logs', [
            'campaign_recipient_id' => $recipient->id,
            'status' => 'failed',
            'response_code' => '421',
            'attempt' => 1,
        ]);

        // Attempt 2: Server recovered, delivery succeeds
        $mockDelivery->simulateFailure(false);
        $job->handle($deliveryManager);

        $recipient->refresh();
        $this->assertEquals(RecipientDeliveryStatus::SENT, $recipient->status);
        $this->assertEquals(2, $recipient->attempts);
        $this->assertEquals(1, $this->campaign->fresh()->sent_count);
    }

    /**
     * Scenario D: Permanent delivery failure (e.g. SMTP 550 or invalid email syntax).
     */
    public function test_scenario_d_permanent_failure_transitions_to_failed_without_rethrow(): void
    {
        Event::fake([EmailDeliveryFailed::class]);

        $recipient = CampaignRecipient::factory()->create([
            'campaign_id' => $this->campaign->id,
            'contact_id' => $this->contact->id,
            'email' => 'user-unknown@example.com',
            'status' => RecipientDeliveryStatus::QUEUED,
        ]);

        $mockDelivery = new MockEmailDeliveryService;
        $mockDelivery->simulateFailure(true, '550 5.1.1 User unknown: recipient address rejected');

        $deliveryManager = new EmailDeliveryManager($this->app);
        $deliveryManager->extend('mock', fn () => $mockDelivery);
        config(['mailforge.delivery.default_mailer' => 'mock']);

        $job = new SendCampaignEmailJob($recipient->id);
        // Permanent failure must NOT throw RuntimeException (no endless queue retries)
        $job->handle($deliveryManager);

        $recipient->refresh();
        $this->assertEquals(RecipientDeliveryStatus::FAILED, $recipient->status);
        $this->assertNotNull($recipient->failed_at);
        $this->assertEquals(1, $this->campaign->fresh()->failed_count);

        Event::assertDispatched(EmailDeliveryFailed::class, 1);
    }

    /**
     * Scenario F & G: Already-sent recipient and active processing lease recipient are skipped.
     */
    public function test_scenario_f_and_g_sent_and_actively_processing_recipients_skipped(): void
    {
        $mockDelivery = new MockEmailDeliveryService;
        $deliveryManager = new EmailDeliveryManager($this->app);
        $deliveryManager->extend('mock', fn () => $mockDelivery);
        config(['mailforge.delivery.default_mailer' => 'mock']);

        // Test F: Already sent
        $sentRecipient = CampaignRecipient::factory()->create([
            'campaign_id' => $this->campaign->id,
            'contact_id' => $this->contact->id,
            'email' => 'sent@example.com',
            'status' => RecipientDeliveryStatus::SENT,
        ]);

        (new SendCampaignEmailJob($sentRecipient->id))->handle($deliveryManager);
        $this->assertCount(0, $mockDelivery->getSentMessages());

        // Test G: Actively processing with fresh lease
        $contact2 = Contact::factory()->create(['user_id' => $this->user->id, 'email' => 'active@example.com']);
        $activeProcessingRecipient = CampaignRecipient::factory()->create([
            'campaign_id' => $this->campaign->id,
            'contact_id' => $contact2->id,
            'email' => 'active@example.com',
            'status' => RecipientDeliveryStatus::PROCESSING,
            'processing_started_at' => now(), // Active lease (0 seconds ago)
        ]);

        (new SendCampaignEmailJob($activeProcessingRecipient->id))->handle($deliveryManager);
        $this->assertCount(0, $mockDelivery->getSentMessages());
    }

    /**
     * Scenario H: Paused campaign prevents sending and restores recipient to queued.
     */
    public function test_scenario_h_paused_campaign_aborts_send_and_restores_queued_status(): void
    {
        $recipient = CampaignRecipient::factory()->create([
            'campaign_id' => $this->campaign->id,
            'contact_id' => $this->contact->id,
            'email' => 'pause-test@example.com',
            'status' => RecipientDeliveryStatus::QUEUED,
        ]);

        // Campaign is paused
        $this->campaign->update(['status' => CampaignStatus::PAUSED]);

        $mockDelivery = new MockEmailDeliveryService;
        $deliveryManager = new EmailDeliveryManager($this->app);
        $deliveryManager->extend('mock', fn () => $mockDelivery);
        config(['mailforge.delivery.default_mailer' => 'mock']);

        $job = new SendCampaignEmailJob($recipient->id);
        $job->handle($deliveryManager);

        $recipient->refresh();
        // Recipient is safely returned to QUEUED
        $this->assertEquals(RecipientDeliveryStatus::QUEUED, $recipient->status);
        $this->assertNull($recipient->processing_started_at);
        $this->assertCount(0, $mockDelivery->getSentMessages());
    }

    /**
     * Scenario I: Cancelled campaign aborts send and transitions recipient to CANCELLED.
     */
    public function test_scenario_i_cancelled_campaign_transitions_recipient_to_cancelled(): void
    {
        $recipient = CampaignRecipient::factory()->create([
            'campaign_id' => $this->campaign->id,
            'contact_id' => $this->contact->id,
            'email' => 'cancel-test@example.com',
            'status' => RecipientDeliveryStatus::QUEUED,
        ]);

        $this->campaign->update(['status' => CampaignStatus::CANCELLED]);

        $mockDelivery = new MockEmailDeliveryService;
        $deliveryManager = new EmailDeliveryManager($this->app);
        $deliveryManager->extend('mock', fn () => $mockDelivery);
        config(['mailforge.delivery.default_mailer' => 'mock']);

        $job = new SendCampaignEmailJob($recipient->id);
        $job->handle($deliveryManager);

        $recipient->refresh();
        $this->assertEquals(RecipientDeliveryStatus::CANCELLED, $recipient->status);
        $this->assertCount(0, $mockDelivery->getSentMessages());
    }

    /**
     * Scenario J & K: Campaign completion and atomic single event dispatch.
     */
    public function test_scenario_j_and_k_campaign_completes_atomically_when_last_recipient_finishes(): void
    {
        Event::fake([CampaignCompleted::class]);

        $this->campaign->update(['status' => CampaignStatus::SENDING]);

        $r1 = CampaignRecipient::factory()->create([
            'campaign_id' => $this->campaign->id,
            'contact_id' => $this->contact->id,
            'email' => 'recip1@example.com',
            'status' => RecipientDeliveryStatus::QUEUED,
        ]);

        $c2 = Contact::factory()->create(['user_id' => $this->user->id, 'email' => 'recip2@example.com']);
        $r2 = CampaignRecipient::factory()->create([
            'campaign_id' => $this->campaign->id,
            'contact_id' => $c2->id,
            'email' => 'recip2@example.com',
            'status' => RecipientDeliveryStatus::QUEUED,
        ]);

        $mockDelivery = new MockEmailDeliveryService;
        $deliveryManager = new EmailDeliveryManager($this->app);
        $deliveryManager->extend('mock', fn () => $mockDelivery);
        config(['mailforge.delivery.default_mailer' => 'mock']);

        // Worker 1 processes recipient 1
        (new SendCampaignEmailJob($r1->id))->handle($deliveryManager);
        $this->assertEquals(CampaignStatus::SENDING, $this->campaign->fresh()->status);
        Event::assertNotDispatched(CampaignCompleted::class);

        // Worker 2 processes recipient 2 (final recipient)
        (new SendCampaignEmailJob($r2->id))->handle($deliveryManager);
        $this->assertEquals(CampaignStatus::COMPLETED, $this->campaign->fresh()->status);
        Event::assertDispatched(CampaignCompleted::class, 1);

        // Re-invoking completion check concurrently does not re-dispatch CampaignCompleted
        (new SendCampaignEmailJob($r2->id))->checkCampaignCompletion($this->campaign);
        Event::assertDispatched(CampaignCompleted::class, 1);
    }

    /**
     * Scenario L: Excel contact import duplicate and database collision protection.
     */
    public function test_scenario_l_contact_import_duplicate_protection(): void
    {
        Contact::factory()->create([
            'user_id' => $this->user->id,
            'email' => 'existing@example.com',
        ]);

        $csvContent = "email,first_name\nexisting@example.com,Existing\nNEW@EXAMPLE.COM,New User\nnew@example.com,New Duplicate\n";
        $file = UploadedFile::fake()->createWithContent('contacts.csv', $csvContent);

        $action = $this->app->make(ImportContactsFromExcelAction::class);
        $summary = $action->execute($this->user, $file);

        $this->assertSame(3, $summary->totalRows);
        $this->assertSame(3, $summary->validRows);
        $this->assertSame(1, $summary->created);
        $this->assertSame(1, $summary->duplicatesInFile);
        $this->assertSame(1, $summary->alreadyExisting);
        $this->assertSame(0, $summary->invalid);

        $this->assertDatabaseHas('contacts', [
            'user_id' => $this->user->id,
            'email' => 'new@example.com',
            'first_name' => 'New User',
        ]);
        $this->assertDatabaseCount('contacts', 3);
    }

    /**
     * Scenario M: Stale processing lease recovery action recovers crashed worker records.
     */
    public function test_scenario_m_stale_processing_recovery_action(): void
    {
        Queue::fake([SendCampaignEmailJob::class]);

        $this->campaign->update(['status' => CampaignStatus::SENDING]);

        // Stale recipient 1: crashed with attempts = 1 (should be reset to QUEUED)
        $stale1 = CampaignRecipient::factory()->create([
            'campaign_id' => $this->campaign->id,
            'contact_id' => $this->contact->id,
            'email' => 'stale1@example.com',
            'status' => RecipientDeliveryStatus::PROCESSING,
            'attempts' => 1,
            'processing_started_at' => now()->subMinutes(10), // Expired lease
        ]);

        // Stale recipient 2: crashed with attempts = 3 (max tries, should be marked FAILED)
        $c2 = Contact::factory()->create(['user_id' => $this->user->id, 'email' => 'stale2@example.com']);
        $stale2 = CampaignRecipient::factory()->create([
            'campaign_id' => $this->campaign->id,
            'contact_id' => $c2->id,
            'email' => 'stale2@example.com',
            'status' => RecipientDeliveryStatus::PROCESSING,
            'attempts' => 3,
            'processing_started_at' => now()->subMinutes(10), // Expired lease
        ]);

        $recoveryAction = new RecoverStaleProcessingRecipientsAction;
        $stats = $recoveryAction->execute(leaseSeconds: 300);

        $this->assertEquals(1, $stats['recovered']);
        $this->assertEquals(1, $stats['failed']);

        $stale1->refresh();
        $this->assertEquals(RecipientDeliveryStatus::QUEUED, $stale1->status);
        $this->assertNull($stale1->processing_started_at);

        $stale2->refresh();
        $this->assertEquals(RecipientDeliveryStatus::FAILED, $stale2->status);
        $this->assertNotNull($stale2->failed_at);
    }

    /**
     * Scenario N: Rate limiting delayed dispatch calculation.
     */
    public function test_scenario_n_rate_limiting_staggers_job_delays(): void
    {
        Queue::fake();

        // Configure 60 emails per minute (1 email per second)
        config(['mailforge.delivery.rate_limit_per_minute' => 60]);

        $c2 = Contact::factory()->create(['user_id' => $this->user->id, 'email' => 'rate2@example.com']);
        $c3 = Contact::factory()->create(['user_id' => $this->user->id, 'email' => 'rate3@example.com']);

        $r1 = CampaignRecipient::factory()->create([
            'campaign_id' => $this->campaign->id,
            'contact_id' => $this->contact->id,
            'status' => RecipientDeliveryStatus::DRAFT,
        ]);
        $r2 = CampaignRecipient::factory()->create([
            'campaign_id' => $this->campaign->id,
            'contact_id' => $c2->id,
            'status' => RecipientDeliveryStatus::DRAFT,
        ]);
        $r3 = CampaignRecipient::factory()->create([
            'campaign_id' => $this->campaign->id,
            'contact_id' => $c3->id,
            'status' => RecipientDeliveryStatus::DRAFT,
        ]);

        $action = new DispatchCampaignSendingAction;
        $dispatched = $action->execute($this->campaign);

        $this->assertEquals(3, $dispatched);
        Queue::assertPushed(SendCampaignEmailJob::class, 3);
    }

    /**
     * Scenario O: AI generation NEVER automatically dispatches sending.
     */
    public function test_scenario_o_ai_generation_never_dispatches_sending(): void
    {
        Queue::fake();

        $aiCampaign = Campaign::factory()->create([
            'user_id' => $this->user->id,
            'status' => CampaignStatus::DRAFT,
            'email_mode' => EmailMode::GENERATE,
            'ai_provider' => 'mock',
            'ai_prompt' => 'Write a VIP invitation',
            'subject' => null,
            'email_body' => null,
        ]);

        $job = new GenerateCampaignEmailJob($aiCampaign->id);
        $processAction = $this->app->make(ProcessCampaignAiContentAction::class);

        $job->handle($processAction);

        $aiCampaign->refresh();
        // Campaign must be in REVIEW, never QUEUED or SENDING
        $this->assertEquals(CampaignStatus::REVIEW, $aiCampaign->status);
        $this->assertNotEmpty($aiCampaign->subject);
        $this->assertNotEmpty($aiCampaign->email_body);

        // Verification: No send job dispatched
        Queue::assertNotPushed(SendCampaignEmailJob::class);
    }

    /**
     * Test resume campaign action resumes queued recipients.
     */
    public function test_resume_campaign_action_re_dispatches_queued_recipients(): void
    {
        Queue::fake();

        $this->campaign->update(['status' => CampaignStatus::PAUSED]);

        CampaignRecipient::factory()->create([
            'campaign_id' => $this->campaign->id,
            'contact_id' => $this->contact->id,
            'status' => RecipientDeliveryStatus::QUEUED,
        ]);

        $resumeAction = new ResumeCampaignAction;
        $resumeAction->execute($this->campaign);

        $this->campaign->refresh();
        $this->assertEquals(CampaignStatus::SENDING, $this->campaign->status);
        Queue::assertPushed(SendCampaignEmailJob::class, 1);
    }
}
