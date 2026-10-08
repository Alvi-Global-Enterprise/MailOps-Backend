<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Products\MailForge\Enums\AiProviderType;
use App\Products\MailForge\Enums\CampaignStatus;
use App\Products\MailForge\Enums\ContactSourceType;
use App\Products\MailForge\Enums\EmailMode;
use App\Products\MailForge\Enums\RecipientDeliveryStatus;
use App\Products\MailForge\Enums\SyncStatus;
use PHPUnit\Framework\TestCase;

class DomainEnumsTest extends TestCase
{
    public function test_campaign_status_values_and_behaviors(): void
    {
        $this->assertSame('draft', CampaignStatus::DRAFT->value);
        $this->assertSame('generating', CampaignStatus::GENERATING->value);
        $this->assertSame('review', CampaignStatus::REVIEW->value);
        $this->assertSame('approved', CampaignStatus::APPROVED->value);
        $this->assertSame('queued', CampaignStatus::QUEUED->value);
        $this->assertSame('sending', CampaignStatus::SENDING->value);
        $this->assertSame('completed', CampaignStatus::COMPLETED->value);
        $this->assertSame('paused', CampaignStatus::PAUSED->value);
        $this->assertSame('cancelled', CampaignStatus::CANCELLED->value);
        $this->assertSame('failed', CampaignStatus::FAILED->value);

        $this->assertTrue(CampaignStatus::DRAFT->canEdit());
        $this->assertTrue(CampaignStatus::REVIEW->canEdit());
        $this->assertFalse(CampaignStatus::QUEUED->canEdit());

        $this->assertTrue(CampaignStatus::DRAFT->canApprove());
        $this->assertTrue(CampaignStatus::REVIEW->canApprove());
        $this->assertFalse(CampaignStatus::COMPLETED->canApprove());

        $this->assertTrue(CampaignStatus::APPROVED->canDispatch());
        $this->assertFalse(CampaignStatus::DRAFT->canDispatch());

        $this->assertTrue(CampaignStatus::COMPLETED->isTerminal());
        $this->assertTrue(CampaignStatus::CANCELLED->isTerminal());
        $this->assertTrue(CampaignStatus::FAILED->isTerminal());
        $this->assertFalse(CampaignStatus::SENDING->isTerminal());
    }

    public function test_recipient_delivery_status_values_and_terminal_states(): void
    {
        $this->assertSame('draft', RecipientDeliveryStatus::DRAFT->value);
        $this->assertSame('queued', RecipientDeliveryStatus::QUEUED->value);
        $this->assertSame('processing', RecipientDeliveryStatus::PROCESSING->value);
        $this->assertSame('sent', RecipientDeliveryStatus::SENT->value);
        $this->assertSame('failed', RecipientDeliveryStatus::FAILED->value);
        $this->assertSame('cancelled', RecipientDeliveryStatus::CANCELLED->value);

        $this->assertTrue(RecipientDeliveryStatus::SENT->isTerminal());
        $this->assertTrue(RecipientDeliveryStatus::FAILED->isTerminal());
        $this->assertTrue(RecipientDeliveryStatus::CANCELLED->isTerminal());
        $this->assertFalse(RecipientDeliveryStatus::PROCESSING->isTerminal());

        $this->assertTrue(RecipientDeliveryStatus::FAILED->canRetry());
        $this->assertFalse(RecipientDeliveryStatus::SENT->canRetry());
    }

    public function test_email_mode_enums(): void
    {
        $this->assertSame('direct', EmailMode::DIRECT->value);
        $this->assertSame('generate', EmailMode::GENERATE->value);
        $this->assertSame('polish', EmailMode::POLISH->value);

        $this->assertFalse(EmailMode::DIRECT->requiresAi());
        $this->assertTrue(EmailMode::GENERATE->requiresAi());
        $this->assertTrue(EmailMode::POLISH->requiresAi());
    }

    public function test_ai_provider_types(): void
    {
        $this->assertSame('openai', AiProviderType::OPENAI->value);
        $this->assertSame('gemini', AiProviderType::GEMINI->value);
        $this->assertSame('mock', AiProviderType::MOCK->value);
    }

    public function test_sync_status_enums(): void
    {
        $this->assertSame('idle', SyncStatus::IDLE->value);
        $this->assertSame('syncing', SyncStatus::SYNCING->value);
        $this->assertSame('completed', SyncStatus::COMPLETED->value);
        $this->assertSame('failed', SyncStatus::FAILED->value);

        $this->assertTrue(SyncStatus::SYNCING->isInProgress());
        $this->assertFalse(SyncStatus::COMPLETED->isInProgress());
    }

    public function test_contact_source_types(): void
    {
        $this->assertSame('google_sheets', ContactSourceType::GOOGLE_SHEETS->value);
        $this->assertSame('manual', ContactSourceType::MANUAL->value);
        $this->assertSame('csv', ContactSourceType::CSV->value);
        $this->assertSame('api', ContactSourceType::API->value);
        $this->assertSame('excel', ContactSourceType::EXCEL->value);
    }
}
