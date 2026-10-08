<?php

declare(strict_types=1);

namespace App\Products\MailForge\DTOs;

readonly class AiPromptPayload
{
    /**
     * @param  array<string, mixed>  $contextVariables  Dynamic template tags (e.g. ['product' => 'MailForge'])
     * @param  array<string, mixed>  $options  Provider overrides (temperature, max_tokens, etc.)
     */
    public function __construct(
        public string $prompt,
        public ?string $roughDraft = null,
        public string $tone = 'professional',
        public ?string $targetAudience = null,
        public ?string $keyPoints = null,
        public array $contextVariables = [],
        public array $options = []
    ) {}
}
