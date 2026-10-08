<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Products\MailForge\DTOs\EmailMessagePayload;
use App\Products\MailForge\Services\Delivery\EmailDeliveryManager;
use App\Products\MailForge\Services\Delivery\Providers\LaravelMailDeliveryService;
use App\Products\MailForge\Services\Delivery\Providers\MockEmailDeliveryService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EmailDeliveryManagerTest extends TestCase
{
    public function test_manager_resolves_configured_and_mock_drivers(): void
    {
        Config::set('mailforge.delivery.default_mailer', 'mock');
        $manager = app(EmailDeliveryManager::class);

        $this->assertInstanceOf(MockEmailDeliveryService::class, $manager->driver());
        $this->assertInstanceOf(LaravelMailDeliveryService::class, $manager->driver('smtp'));
        $this->assertInstanceOf(MockEmailDeliveryService::class, $manager->driver('mock'));
    }

    public function test_laravel_mail_delivery_service_dispatches_email_successfully(): void
    {
        Mail::fake();

        $service = new LaravelMailDeliveryService(mailer: 'array');

        $payload = new EmailMessagePayload(
            to: 'prospect@acmecorp.com',
            subject: 'Exciting Partnership Opportunity',
            bodyHtml: '<h1>Welcome!</h1><p>We are thrilled to connect.</p>',
            bodyText: "Welcome!\nWe are thrilled to connect.",
            fromEmail: 'sales@mailforge.ai',
            fromName: 'MailForge Sales',
            replyTo: 'replies@mailforge.ai',
            headers: [
                'X-Campaign-ID' => 'cmp-998',
            ]
        );

        $result = $service->send($payload);

        $this->assertTrue($result->success);
        $this->assertSame('array', $result->provider);
        $this->assertSame('250', $result->responseCode);
        $this->assertNotNull($result->messageId);
        $this->assertNull($result->errorMessage);

        Mail::assertSentCount(1);
    }

    public function test_mock_delivery_service_tracks_sent_messages_and_simulates_failure(): void
    {
        $mockService = new MockEmailDeliveryService;

        $payload = new EmailMessagePayload(
            to: 'lead@example.com',
            subject: 'Hello Lead',
            bodyHtml: '<p>Testing mock delivery</p>'
        );

        $result = $mockService->send($payload);

        $this->assertTrue($result->success);
        $this->assertCount(1, $mockService->getDeliveredMessages());
        $this->assertSame('lead@example.com', $mockService->getDeliveredMessages()[0]->to);

        // Test failure simulation
        $mockService->setShouldFail(true, 'SMTP host connection timeout');

        $failedResult = $mockService->send($payload);

        $this->assertFalse($failedResult->success);
        $this->assertSame('SMTP host connection timeout', $failedResult->errorMessage);
        $this->assertSame('550', $failedResult->responseCode);
    }
}
