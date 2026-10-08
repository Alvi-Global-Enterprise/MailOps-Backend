<?php

declare(strict_types=1);

namespace App\Products\MailForge\Services\Delivery\Providers;

use App\Products\MailForge\Contracts\EmailDeliveryServiceInterface;
use App\Products\MailForge\DTOs\DeliveryResult;
use App\Products\MailForge\DTOs\EmailMessagePayload;

class MockEmailDeliveryService implements EmailDeliveryServiceInterface
{
    /**
     * @var list<EmailMessagePayload> In-memory log of sent messages
     */
    protected array $deliveredMessages = [];

    /**
     * @var bool Whether subsequent send calls should simulate failure
     */
    protected bool $shouldFail = false;

    protected string $failureReason = 'Simulated delivery failure';

    protected string $failureResponseCode = '550';

    public function getName(): string
    {
        return 'mock';
    }

    public function setShouldFail(bool $shouldFail, string $reason = 'Simulated delivery failure', string $responseCode = '550'): self
    {
        $this->shouldFail = $shouldFail;
        $this->failureReason = $reason;
        $this->failureResponseCode = $responseCode;

        return $this;
    }

    public function simulateFailure(bool $shouldFail, string $reason = 'Simulated delivery failure', string $responseCode = '550'): self
    {
        return $this->setShouldFail($shouldFail, $reason, $responseCode);
    }

    public function send(EmailMessagePayload $message): DeliveryResult
    {
        if ($this->shouldFail || str_contains($message->to, 'fail@')) {
            return DeliveryResult::failed(
                provider: 'mock',
                errorMessage: $this->failureReason,
                responseCode: $this->failureResponseCode
            );
        }

        $this->deliveredMessages[] = $message;

        return DeliveryResult::successful(
            provider: 'mock',
            messageId: 'mock-msg-'.bin2hex(random_bytes(6)),
            responseCode: '250',
            rawResponse: 'OK: delivered to in-memory store'
        );
    }

    /**
     * @return list<EmailMessagePayload>
     */
    public function getDeliveredMessages(): array
    {
        return $this->deliveredMessages;
    }

    /**
     * @return list<EmailMessagePayload>
     */
    public function getSentMessages(): array
    {
        return $this->deliveredMessages;
    }

    public function reset(): void
    {
        $this->deliveredMessages = [];
        $this->shouldFail = false;
    }
}
