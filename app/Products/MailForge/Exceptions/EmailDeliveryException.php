<?php

declare(strict_types=1);

namespace App\Products\MailForge\Exceptions;

use Exception;
use Throwable;

class EmailDeliveryException extends Exception
{
    public function __construct(
        string $message,
        public readonly string $provider = 'smtp',
        public readonly ?string $recipientEmail = null,
        public readonly ?string $responseCode = null,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 500, $previous);
    }

    public static function sendFailed(
        string $provider,
        string $recipientEmail,
        string $reason,
        ?string $responseCode = null,
        ?Throwable $previous = null
    ): self {
        return new self(
            "Failed to deliver email to [{$recipientEmail}]: {$reason}",
            $provider,
            $recipientEmail,
            $responseCode,
            $previous
        );
    }
}
