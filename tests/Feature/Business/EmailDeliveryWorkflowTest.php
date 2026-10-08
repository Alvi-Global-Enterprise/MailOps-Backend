<?php

declare(strict_types=1);

namespace Tests\Feature\Business;

use App\Models\User;
use App\Products\MailForge\Enums\CampaignStatus;
use App\Products\MailForge\Enums\RecipientDeliveryStatus;
use App\Products\MailForge\Events\CampaignCompleted;
use App\Products\MailForge\Events\EmailDeliveryFailed;
use App\Products\MailForge\Events\EmailSent;
use App\Products\MailForge\Jobs\SendCampaignEmailJob;
use App\Products\MailForge\Models\Campaign;
use App\Products\MailForge\Models\CampaignRecipient;
use App\Products\MailForge\Models\Contact;
use App\Products\MailForge\Services\Delivery\EmailDeliveryManager;
use App\Products\MailForge\Services\Delivery\Providers\MockEmailDeliveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class EmailDeliveryWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('mailforge.delivery.default_mailer', 'mock');
    }

    public function test_send_campaign_email_job_delivers_and_updates_states(): void
    {
        Event::fake([EmailSent::class, CampaignCompleted::class]);

        $user = User::factory()->create();
        $contact = Contact::factory()->create([
            'user_id' => $user->id,
            'first_name' => 'John',
            'company' => 'Acme Inc',
            'custom_data' => ['tier' => 'Enterprise'],
        ]);

        $campaign = Campaign::factory()->create([
            'user_id' => $user->id,
            'subject' => 'Hi {{first_name}}, welcome to {{company}}!',
            'email_body' => '<p>Your tier is {{tier}}.</p>',
            'status' => CampaignStatus::SENDING,
        ]);

        $recipient = CampaignRecipient::factory()->create([
            'campaign_id' => $campaign->id,
            'contact_id' => $contact->id,
            'email' => $contact->email,
            'subject' => 'Hi {{first_name}}, welcome to {{company}}!',
            'personalized_body' => '<p>Your tier is {{tier}}.</p>',
            'status' => RecipientDeliveryStatus::QUEUED,
        ]);

        $mockDelivery = new MockEmailDeliveryService;
        $deliveryManager = app(EmailDeliveryManager::class);
        $deliveryManager->extend('mock', fn () => $mockDelivery);

        $job = new SendCampaignEmailJob($recipient->id);
        $job->handle($deliveryManager);

        $freshRecipient = $recipient->fresh();
        $this->assertSame(RecipientDeliveryStatus::SENT, $freshRecipient->status);
        $this->assertNotNull($freshRecipient->sent_at);
        $this->assertNotNull($freshRecipient->provider_message_id);

        // Verify personalization in delivered message
        $messages = $mockDelivery->getDeliveredMessages();
        $this->assertCount(1, $messages);
        $this->assertSame('Hi John, welcome to Acme Inc!', $messages[0]->subject);
        $this->assertStringContainsString('Your tier is Enterprise.', $messages[0]->bodyHtml);

        // Verify delivery log
        $this->assertDatabaseHas('email_delivery_logs', [
            'campaign_recipient_id' => $recipient->id,
            'campaign_id' => $campaign->id,
            'status' => 'sent',
        ]);

        // Verify campaign counter & completion
        $freshCampaign = $campaign->fresh();
        $this->assertSame(1, $freshCampaign->sent_count);
        $this->assertSame(CampaignStatus::COMPLETED, $freshCampaign->status);
        $this->assertNotNull($freshCampaign->completed_at);

        Event::assertDispatched(EmailSent::class);
        Event::assertDispatched(CampaignCompleted::class);
    }

    public function test_send_campaign_email_job_idempotency_prevents_duplicate_sends(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->create(['user_id' => $user->id]);
        $campaign = Campaign::factory()->create([
            'user_id' => $user->id,
            'status' => CampaignStatus::SENDING,
        ]);

        $recipient = CampaignRecipient::factory()->create([
            'campaign_id' => $campaign->id,
            'contact_id' => $contact->id,
            'email' => $contact->email,
            'status' => RecipientDeliveryStatus::QUEUED,
        ]);

        $mockDelivery = new MockEmailDeliveryService;
        $deliveryManager = app(EmailDeliveryManager::class);
        $deliveryManager->extend('mock', fn () => $mockDelivery);

        $job1 = new SendCampaignEmailJob($recipient->id);
        $job2 = new SendCampaignEmailJob($recipient->id);

        // First worker runs
        $job1->handle($deliveryManager);
        $this->assertCount(1, $mockDelivery->getDeliveredMessages());

        // Second worker tries to process the exact same recipient
        $job2->handle($deliveryManager);
        // Message count remains 1 because recipient status was no longer QUEUED!
        $this->assertCount(1, $mockDelivery->getDeliveredMessages());
    }

    public function test_send_campaign_email_job_handles_permanent_failure(): void
    {
        Event::fake([EmailDeliveryFailed::class]);

        $user = User::factory()->create();
        $contact = Contact::factory()->create(['user_id' => $user->id]);
        $campaign = Campaign::factory()->create([
            'user_id' => $user->id,
            'status' => CampaignStatus::SENDING,
        ]);

        $recipient = CampaignRecipient::factory()->create([
            'campaign_id' => $campaign->id,
            'contact_id' => $contact->id,
            'email' => $contact->email,
            'status' => RecipientDeliveryStatus::QUEUED,
        ]);

        $mockDelivery = new MockEmailDeliveryService;
        $mockDelivery->setShouldFail(true, 'SMTP user rejected');

        $deliveryManager = app(EmailDeliveryManager::class);
        $deliveryManager->extend('mock-fail', fn () => $mockDelivery);

        $job = new SendCampaignEmailJob($recipient->id);

        // Call failed handler directly to test permanent failure resolution
        $job->failed(new \RuntimeException('SMTP user rejected'));

        $freshRecipient = $recipient->fresh();
        $this->assertSame(RecipientDeliveryStatus::FAILED, $freshRecipient->status);
        $this->assertNotNull($freshRecipient->failed_at);
        $this->assertSame('SMTP user rejected', $freshRecipient->error_message);

        $this->assertSame(1, $campaign->fresh()->failed_count);
    }
}
