<?php

declare(strict_types=1);

namespace App\Products\MailForge\Contracts;

use App\Products\MailForge\DTOs\AiGeneratedEmail;
use App\Products\MailForge\DTOs\AiPromptPayload;

interface AIEmailProviderInterface
{
    /**
     * Generate an email subject and body from an instructional prompt.
     */
    public function generateEmail(AiPromptPayload $payload): AiGeneratedEmail;

    /**
     * Polish and refine a rough user draft into a professional email.
     */
    public function polishEmail(AiPromptPayload $payload): AiGeneratedEmail;

    /**
     * Rewrite an existing email with an alternate tone or structure.
     */
    public function rewriteEmail(AiPromptPayload $payload): AiGeneratedEmail;

    /**
     * Get the unique provider identifier (e.g. 'openai', 'gemini', 'mock').
     */
    public function getName(): string;
}
