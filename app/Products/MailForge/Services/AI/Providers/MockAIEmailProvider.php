<?php

declare(strict_types=1);

namespace App\Products\MailForge\Services\AI\Providers;

use App\Products\MailForge\Contracts\AIEmailProviderInterface;
use App\Products\MailForge\DTOs\AiGeneratedEmail;
use App\Products\MailForge\DTOs\AiPromptPayload;
use App\Products\MailForge\Exceptions\AIProviderException;

class MockAIEmailProvider implements AIEmailProviderInterface
{
    /**
     * @var callable|null Optional callback to intercept or simulate custom provider behavior
     */
    protected $handler = null;

    public function __construct(
        protected string $model = 'mock-copywriter-v1',
        ?callable $handler = null
    ) {
        $this->handler = $handler;
    }

    public function getName(): string
    {
        return 'mock';
    }

    public function setHandler(?callable $handler): self
    {
        $this->handler = $handler;

        return $this;
    }

    public function generateEmail(AiPromptPayload $payload): AiGeneratedEmail
    {
        if ($this->handler !== null) {
            return call_user_func($this->handler, 'generate', $payload);
        }

        if (str_contains(strtolower($payload->prompt), 'simulate_error')) {
            throw AIProviderException::apiError('mock', 'Simulated API failure for test suite.');
        }

        $subject = 'Introduction to our solutions: '.ucfirst(substr($payload->prompt, 0, 30));
        $bodyHtml = '<p>Hello,</p><p>Based on your request: '.htmlspecialchars($payload->prompt).'</p><p>Best regards,<br>The Team</p>';
        $bodyText = "Hello,\n\nBased on your request: {$payload->prompt}\n\nBest regards,\nThe Team";

        return new AiGeneratedEmail(
            subject: $subject,
            bodyHtml: $bodyHtml,
            bodyText: $bodyText,
            provider: 'mock',
            model: $this->model,
            promptTokens: 50,
            completionTokens: 100,
            totalTokens: 150,
            durationMs: 15
        );
    }

    public function polishEmail(AiPromptPayload $payload): AiGeneratedEmail
    {
        if ($this->handler !== null) {
            return call_user_func($this->handler, 'polish', $payload);
        }

        $draft = $payload->roughDraft ?: $payload->prompt;
        $subject = 'Polished Outreach: Connecting regarding your workflow';
        $bodyHtml = '<p>Dear Partner,</p><p>'.htmlspecialchars($draft).'</p><p>Looking forward to speaking soon.</p>';
        $bodyText = "Dear Partner,\n\n{$draft}\n\nLooking forward to speaking soon.";

        return new AiGeneratedEmail(
            subject: $subject,
            bodyHtml: $bodyHtml,
            bodyText: $bodyText,
            provider: 'mock',
            model: $this->model,
            promptTokens: 60,
            completionTokens: 120,
            totalTokens: 180,
            durationMs: 12
        );
    }

    public function rewriteEmail(AiPromptPayload $payload): AiGeneratedEmail
    {
        if ($this->handler !== null) {
            return call_user_func($this->handler, 'rewrite', $payload);
        }

        $draft = $payload->roughDraft ?: $payload->prompt;
        $subject = 'Rewritten: Reimagining our partnership';
        $bodyHtml = '<p>Hi there,</p><p>Here is an alternative angle: '.htmlspecialchars($draft).'</p>';
        $bodyText = "Hi there,\n\nHere is an alternative angle: {$draft}";

        return new AiGeneratedEmail(
            subject: $subject,
            bodyHtml: $bodyHtml,
            bodyText: $bodyText,
            provider: 'mock',
            model: $this->model,
            promptTokens: 55,
            completionTokens: 110,
            totalTokens: 165,
            durationMs: 14
        );
    }
}
