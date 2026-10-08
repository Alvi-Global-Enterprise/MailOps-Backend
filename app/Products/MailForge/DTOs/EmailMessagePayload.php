<?php

declare(strict_types=1);

namespace App\Products\MailForge\DTOs;

readonly class EmailMessagePayload
{
    /**
     * @param  array<string, string>  $headers  Custom email headers
     */
    public function __construct(
        public string $to,
        public string $subject,
        public string $bodyHtml,
        public ?string $bodyText = null,
        public ?string $fromEmail = null,
        public ?string $fromName = null,
        public ?string $replyTo = null,
        public array $headers = []
    ) {}
}
