<?php

declare(strict_types=1);

namespace App\Products\MailForge\Contracts;

use App\Products\MailForge\DTOs\DeliveryResult;
use App\Products\MailForge\DTOs\EmailMessagePayload;

interface EmailDeliveryServiceInterface
{
    /**
     * Deliver the message payload through the active provider.
     */
    public function send(EmailMessagePayload $message): DeliveryResult;

    /**
     * Return the provider identifier name.
     */
    public function getName(): string;
}
