<?php

declare(strict_types=1);

namespace App\Products\MailForge\DTOs;

readonly class AiGeneratedEmail
{
    /**
     * @param  array<string, mixed>  $rawResponse  Provider response snapshot
     */
    public function __construct(
        public string $subject,
        public string $bodyHtml,
        public string $bodyText,
        public string $provider,
        public string $model,
        public int $promptTokens = 0,
        public int $completionTokens = 0,
        public int $totalTokens = 0,
        public int $durationMs = 0,
        public array $rawResponse = []
    ) {}
}
