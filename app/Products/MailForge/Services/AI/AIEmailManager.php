<?php

declare(strict_types=1);

namespace App\Products\MailForge\Services\AI;

use App\Products\MailForge\Contracts\AIEmailProviderInterface;
use App\Products\MailForge\DTOs\AiGeneratedEmail;
use App\Products\MailForge\DTOs\AiPromptPayload;
use App\Products\MailForge\Services\AI\Providers\GeminiEmailProvider;
use App\Products\MailForge\Services\AI\Providers\MockAIEmailProvider;
use App\Products\MailForge\Services\AI\Providers\OpenAIEmailProvider;
use Illuminate\Support\Manager;

/**
 * @method AIEmailProviderInterface driver($driver = null)
 */
class AIEmailManager extends Manager
{
    /**
     * Get the default driver name.
     */
    public function getDefaultDriver(): string
    {
        return (string) config('mailforge.ai.default_provider', 'openai');
    }

    /**
     * Create the OpenAI driver instance.
     */
    public function createOpenaiDriver(): AIEmailProviderInterface
    {
        return new OpenAIEmailProvider(
            apiKey: config('mailforge.ai.providers.openai.api_key'),
            model: (string) config('mailforge.ai.providers.openai.model', 'gpt-4o-mini'),
            organization: config('mailforge.ai.providers.openai.organization'),
            timeout: (int) config('mailforge.ai.providers.openai.timeout', 30)
        );
    }

    /**
     * Create the Google Gemini driver instance.
     */
    public function createGeminiDriver(): AIEmailProviderInterface
    {
        return new GeminiEmailProvider(
            apiKey: config('mailforge.ai.providers.gemini.api_key'),
            model: (string) config('mailforge.ai.providers.gemini.model', 'gemini-3.5-flash'),
            timeout: (int) config('mailforge.ai.providers.gemini.timeout', 30)
        );
    }

    /**
     * Create the deterministic mock driver instance.
     */
    public function createMockDriver(): AIEmailProviderInterface
    {
        return new MockAIEmailProvider(
            model: (string) config('mailforge.ai.providers.mock.model', 'mock-copywriter-v1')
        );
    }

    /**
     * Convenience method to generate an email with the designated or default driver.
     */
    public function generateEmail(AiPromptPayload $payload, ?string $provider = null): AiGeneratedEmail
    {
        /** @var AIEmailProviderInterface $driver */
        $driver = $this->driver($provider);

        return $driver->generateEmail($payload);
    }

    /**
     * Convenience method to polish an email with the designated or default driver.
     */
    public function polishEmail(AiPromptPayload $payload, ?string $provider = null): AiGeneratedEmail
    {
        /** @var AIEmailProviderInterface $driver */
        $driver = $this->driver($provider);

        return $driver->polishEmail($payload);
    }

    /**
     * Convenience method to rewrite an email with the designated or default driver.
     */
    public function rewriteEmail(AiPromptPayload $payload, ?string $provider = null): AiGeneratedEmail
    {
        /** @var AIEmailProviderInterface $driver */
        $driver = $this->driver($provider);

        return $driver->rewriteEmail($payload);
    }
}
