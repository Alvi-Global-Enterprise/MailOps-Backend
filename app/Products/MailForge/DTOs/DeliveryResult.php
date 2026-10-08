<?php

declare(strict_types=1);

namespace App\Products\MailForge\DTOs;

readonly class DeliveryResult
{
    public function __construct(
        public bool $success,
        public string $provider,
        public ?string $messageId = null,
        public ?string $responseCode = null,
        public ?string $rawResponse = null,
        public ?string $errorMessage = null
    ) {}

    public static function successful(string $provider, ?string $messageId = null, ?string $responseCode = '250', ?string $rawResponse = null): self
    {
        return new self(
            success: true,
            provider: $provider,
            messageId: $messageId,
            responseCode: $responseCode,
            rawResponse: $rawResponse,
            errorMessage: null
        );
    }

    public static function failed(string $provider, string $errorMessage, ?string $responseCode = null, ?string $rawResponse = null): self
    {
        return new self(
            success: false,
            provider: $provider,
            messageId: null,
            responseCode: $responseCode,
            rawResponse: $rawResponse,
            errorMessage: $errorMessage
        );
    }
}
