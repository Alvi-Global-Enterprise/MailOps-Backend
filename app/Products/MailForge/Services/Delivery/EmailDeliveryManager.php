<?php

declare(strict_types=1);

namespace App\Products\MailForge\Services\Delivery;

use App\Products\MailForge\Contracts\EmailDeliveryServiceInterface;
use App\Products\MailForge\DTOs\DeliveryResult;
use App\Products\MailForge\DTOs\EmailMessagePayload;
use App\Products\MailForge\Services\Delivery\Providers\LaravelMailDeliveryService;
use App\Products\MailForge\Services\Delivery\Providers\MockEmailDeliveryService;
use Illuminate\Support\Manager;

/**
 * @method EmailDeliveryServiceInterface driver($driver = null)
 */
class EmailDeliveryManager extends Manager
{
    /**
     * Get the default driver name.
     */
    public function getDefaultDriver(): string
    {
        return (string) config('mailforge.delivery.default_mailer', 'smtp');
    }

    /**
     * Create the SMTP / Laravel Mail driver.
     */
    public function createSmtpDriver(): EmailDeliveryServiceInterface
    {
        return new LaravelMailDeliveryService('smtp');
    }

    /**
     * Create default Laravel Mailer driver.
     */
    public function createLaravelDriver(): EmailDeliveryServiceInterface
    {
        return new LaravelMailDeliveryService(null);
    }

    /**
     * Create Log driver.
     */
    public function createLogDriver(): EmailDeliveryServiceInterface
    {
        return new LaravelMailDeliveryService('log');
    }

    /**
     * Create Array mailer driver (common in tests).
     */
    public function createArrayDriver(): EmailDeliveryServiceInterface
    {
        return new LaravelMailDeliveryService('array');
    }

    /**
     * Create the deterministic Mock driver.
     */
    public function createMockDriver(): EmailDeliveryServiceInterface
    {
        return new MockEmailDeliveryService;
    }

    /**
     * Convenience method to send an email payload using designated or default driver.
     */
    public function send(EmailMessagePayload $message, ?string $provider = null): DeliveryResult
    {
        /** @var EmailDeliveryServiceInterface $driver */
        $driver = $this->driver($provider);

        return $driver->send($message);
    }
}
