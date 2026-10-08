<?php

declare(strict_types=1);

namespace App\Products\MailForge\Services\Delivery;

use App\Products\MailForge\DTOs\EmailMessagePayload;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

class CampaignMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly EmailMessagePayload $payload
    ) {}

    public function envelope(): Envelope
    {
        $from = null;
        if (! empty($this->payload->fromEmail)) {
            $from = new Address(
                $this->payload->fromEmail,
                $this->payload->fromName ?: (string) config('mail.from.name', '')
            );
        }

        $replyTo = [];
        if (! empty($this->payload->replyTo)) {
            $replyTo[] = new Address($this->payload->replyTo);
        }

        return new Envelope(
            from: $from,
            to: [$this->payload->to],
            replyTo: $replyTo,
            subject: $this->payload->subject
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: $this->payload->bodyHtml
        );
    }

    public function headers(): Headers
    {
        return new Headers(
            text: $this->payload->headers
        );
    }
}
