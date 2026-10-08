<?php

declare(strict_types=1);

namespace App\Products\MailForge\Services\Delivery\Providers;

use App\Products\MailForge\Contracts\EmailDeliveryServiceInterface;
use App\Products\MailForge\DTOs\DeliveryResult;
use App\Products\MailForge\DTOs\EmailMessagePayload;
use App\Products\MailForge\Services\Delivery\CampaignMail;
use Illuminate\Mail\SentMessage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class LaravelMailDeliveryService implements EmailDeliveryServiceInterface
{
    public function __construct(
        protected ?string $mailer = null
    ) {
        $this->mailer = $mailer ?: config('mailforge.delivery.default_mailer');
    }

    public function getName(): string
    {
        return $this->mailer ?: 'laravel-mail';
    }

    public function send(EmailMessagePayload $message): DeliveryResult
    {
        $startTime = microtime(true);
        $fromEmail = $message->fromEmail ?: config('mail.from.address');
        $fromName = $message->fromName ?: config('mail.from.name');

        try {
            $mailer = $this->mailer ? Mail::mailer($this->mailer) : Mail::mailer();

            $mailable = new CampaignMail($message);

            /** @var SentMessage|null $sentMessage */
            $sentMessage = $mailer->send($mailable);

            $durationMs = (int) round((microtime(true) - $startTime) * 1000);
            $messageId = $sentMessage ? $sentMessage->getMessageId() : 'msg-'.bin2hex(random_bytes(8));

            Log::info('Email sent successfully via Laravel Mail', [
                'provider' => $this->getName(),
                'recipient' => $this->maskEmail($message->to),
                'message_id' => $messageId,
                'duration_ms' => $durationMs,
            ]);

            return DeliveryResult::successful(
                provider: $this->getName(),
                messageId: $messageId,
                responseCode: '250',
                rawResponse: 'Message accepted for delivery'
            );
        } catch (Throwable $e) {
            $durationMs = (int) round((microtime(true) - $startTime) * 1000);

            Log::error('Email delivery failed via Laravel Mail', [
                'provider' => $this->getName(),
                'recipient' => $this->maskEmail($message->to),
                'error' => $e->getMessage(),
                'duration_ms' => $durationMs,
            ]);

            return DeliveryResult::failed(
                provider: $this->getName(),
                errorMessage: $e->getMessage(),
                responseCode: (string) ($e->getCode() ?: '500'),
                rawResponse: $e->getMessage()
            );
        }
    }

    /**
     * Mask email for safe logging (e.g. j***n@domain.com).
     */
    protected function maskEmail(string $email): string
    {
        $parts = explode('@', $email);
        if (count($parts) !== 2) {
            return '***@***';
        }

        $user = $parts[0];
        $domain = $parts[1];

        $maskedUser = strlen($user) <= 2
            ? $user[0].'*'
            : $user[0].str_repeat('*', max(1, strlen($user) - 2)).substr($user, -1);

        return "{$maskedUser}@{$domain}";
    }
}
