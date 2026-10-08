<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Products\MailForge\DTOs\AiPromptPayload;
use App\Products\MailForge\Exceptions\AIProviderException;
use App\Products\MailForge\Services\AI\AIEmailManager;
use App\Products\MailForge\Services\AI\Providers\GeminiEmailProvider;
use App\Products\MailForge\Services\AI\Providers\MockAIEmailProvider;
use App\Products\MailForge\Services\AI\Providers\OpenAIEmailProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AIEmailManagerTest extends TestCase
{
    public function test_manager_resolves_configured_and_explicit_drivers(): void
    {
        Config::set('mailforge.ai.default_provider', 'mock');
        $manager = app(AIEmailManager::class);

        $this->assertInstanceOf(MockAIEmailProvider::class, $manager->driver());
        $this->assertSame('mock', $manager->driver()->getName());

        $this->assertInstanceOf(OpenAIEmailProvider::class, $manager->driver('openai'));
        $this->assertSame('openai', $manager->driver('openai')->getName());

        $this->assertInstanceOf(GeminiEmailProvider::class, $manager->driver('gemini'));
        $this->assertSame('gemini', $manager->driver('gemini')->getName());
    }

    public function test_mock_provider_returns_deterministic_generated_email(): void
    {
        $manager = app(AIEmailManager::class);

        $payload = new AiPromptPayload(
            prompt: 'Introduce our modern email SaaS platform',
            tone: 'professional',
            targetAudience: 'CTOs and VPs of Engineering'
        );

        $result = $manager->generateEmail($payload, 'mock');

        $this->assertSame('mock', $result->provider);
        $this->assertSame('mock-copywriter-v1', $result->model);
        $this->assertStringContainsString('Introduction to our solutions', $result->subject);
        $this->assertStringContainsString('Introduce our modern email SaaS platform', $result->bodyHtml);
        $this->assertGreaterThan(0, $result->totalTokens);
    }

    public function test_mock_provider_supports_polish_and_rewrite(): void
    {
        $manager = app(AIEmailManager::class);

        $polishPayload = new AiPromptPayload(
            prompt: 'Polish this draft',
            roughDraft: 'Hey there we have cool tools check them out'
        );

        $polished = $manager->polishEmail($polishPayload, 'mock');
        $this->assertStringContainsString('Polished Outreach', $polished->subject);
        $this->assertStringContainsString('Hey there we have cool tools', $polished->bodyHtml);

        $rewritePayload = new AiPromptPayload(
            prompt: 'Rewrite draft',
            roughDraft: 'Original text here'
        );

        $rewritten = $manager->rewriteEmail($rewritePayload, 'mock');
        $this->assertStringContainsString('Rewritten:', $rewritten->subject);
    }

    public function test_openai_provider_sends_structured_request_and_parses_json(): void
    {
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'id' => 'chatcmpl-test-123',
                'object' => 'chat.completion',
                'choices' => [
                    [
                        'message' => [
                            'role' => 'assistant',
                            'content' => json_encode([
                                'subject' => 'Transform your email outreach with MailForge',
                                'body_html' => '<p>Hello Team,</p><p>Explore our automation suite.</p>',
                                'body_text' => "Hello Team,\nExplore our automation suite.",
                            ]),
                        ],
                    ],
                ],
                'usage' => [
                    'prompt_tokens' => 125,
                    'completion_tokens' => 85,
                    'total_tokens' => 210,
                ],
            ], 200),
        ]);

        $provider = new OpenAIEmailProvider(
            apiKey: 'sk-test-key-123',
            model: 'gpt-4o-mini'
        );

        $payload = new AiPromptPayload(
            prompt: 'Write an enterprise outreach email',
            tone: 'compelling'
        );

        $email = $provider->generateEmail($payload);

        $this->assertSame('openai', $email->provider);
        $this->assertSame('gpt-4o-mini', $email->model);
        $this->assertSame('Transform your email outreach with MailForge', $email->subject);
        $this->assertSame('<p>Hello Team,</p><p>Explore our automation suite.</p>', $email->bodyHtml);
        $this->assertSame(125, $email->promptTokens);
        $this->assertSame(85, $email->completionTokens);
        $this->assertSame(210, $email->totalTokens);
        $this->assertGreaterThanOrEqual(0, $email->durationMs);
    }

    public function test_openai_provider_handles_rate_limiting(): void
    {
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'error' => [
                    'message' => 'Rate limit reached for requests',
                    'type' => 'requests',
                    'code' => 'rate_limit_exceeded',
                ],
            ], 429, ['Retry-After' => '20']),
        ]);

        $provider = new OpenAIEmailProvider(apiKey: 'sk-test');

        $this->expectException(AIProviderException::class);
        $this->expectExceptionMessage('Rate limit exceeded. Retry after 20 seconds.');

        $provider->generateEmail(new AiPromptPayload(prompt: 'Test prompt'));
    }

    public function test_openai_provider_handles_insufficient_quota(): void
    {
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'error' => [
                    'message' => 'You exceeded your current quota',
                    'type' => 'insufficient_quota',
                    'code' => 'insufficient_quota',
                ],
            ], 429),
        ]);

        $provider = new OpenAIEmailProvider(apiKey: 'sk-test');

        $this->expectException(AIProviderException::class);
        $this->expectExceptionMessage('API quota exceeded or insufficient account balance.');

        $provider->generateEmail(new AiPromptPayload(prompt: 'Test prompt'));
    }

    public function test_gemini_provider_sends_structured_request_and_extracts_tokens(): void
    {
        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                [
                                    'text' => json_encode([
                                        'subject' => 'Next-gen Outreach Powered by Gemini',
                                        'body_html' => '<p>Greetings,</p><p>Here is our proposal.</p>',
                                        'body_text' => "Greetings,\nHere is our proposal.",
                                    ]),
                                ],
                            ],
                        ],
                    ],
                ],
                'usageMetadata' => [
                    'promptTokenCount' => 95,
                    'candidatesTokenCount' => 60,
                    'totalTokenCount' => 155,
                ],
            ], 200),
        ]);

        $provider = new GeminiEmailProvider(
            apiKey: 'gemini-secret-api-key',
            model: 'gemini-1.5-flash'
        );

        $payload = new AiPromptPayload(
            prompt: 'Generate B2B outreach',
            tone: 'friendly'
        );

        $result = $provider->generateEmail($payload);

        $this->assertSame('gemini', $result->provider);
        $this->assertSame('gemini-1.5-flash', $result->model);
        $this->assertSame('Next-gen Outreach Powered by Gemini', $result->subject);
        $this->assertSame('<p>Greetings,</p><p>Here is our proposal.</p>', $result->bodyHtml);
        $this->assertSame(95, $result->promptTokens);
        $this->assertSame(60, $result->completionTokens);
        $this->assertSame(155, $result->totalTokens);
    }

    public function test_gemini_provider_handles_invalid_api_key(): void
    {
        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent*' => Http::response([
                'error' => [
                    'code' => 400,
                    'message' => 'API key not valid. Please pass a valid API key.',
                    'status' => 'INVALID_ARGUMENT',
                ],
            ], 400),
        ]);

        $provider = new GeminiEmailProvider(apiKey: 'invalid-key');

        $this->expectException(AIProviderException::class);
        $this->expectExceptionMessage('Authentication failed. Please verify API key configuration.');

        $provider->generateEmail(new AiPromptPayload(prompt: 'Test prompt'));
    }

    public function test_openai_provider_polishes_email_successfully(): void
    {
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'id' => 'chatcmpl-polish-1',
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'subject' => 'Polished Subject Line',
                                'body_html' => '<p>Polished body content.</p>',
                                'body_text' => 'Polished body content.',
                            ]),
                        ],
                    ],
                ],
                'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 50, 'total_tokens' => 100],
            ], 200),
        ]);

        $provider = new OpenAIEmailProvider(apiKey: 'sk-test');
        $result = $provider->polishEmail(new AiPromptPayload(
            prompt: 'Refine this draft',
            roughDraft: 'rough unpolished text'
        ));

        $this->assertSame('Polished Subject Line', $result->subject);
        $this->assertSame('<p>Polished body content.</p>', $result->bodyHtml);
        $this->assertSame(100, $result->totalTokens);
    }

    public function test_openai_provider_throws_on_missing_api_key(): void
    {
        $provider = new OpenAIEmailProvider(apiKey: '');

        $this->expectException(AIProviderException::class);
        $this->expectExceptionMessage('Authentication failed. Please verify API key configuration.');

        $provider->generateEmail(new AiPromptPayload(prompt: 'Test prompt'));
    }

    public function test_openai_provider_handles_401_authentication_failure(): void
    {
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'error' => ['message' => 'Incorrect API key provided'],
            ], 401),
        ]);

        $provider = new OpenAIEmailProvider(apiKey: 'sk-bad-key');

        $this->expectException(AIProviderException::class);
        $this->expectExceptionMessage('Authentication failed. Please verify API key configuration.');

        $provider->generateEmail(new AiPromptPayload(prompt: 'Test prompt'));
    }

    public function test_openai_provider_handles_connection_timeout(): void
    {
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => fn () => throw new ConnectionException('Connection timed out'),
        ]);

        $provider = new OpenAIEmailProvider(apiKey: 'sk-test', timeout: 5);

        $this->expectException(AIProviderException::class);
        $this->expectExceptionMessage('Request timed out after 5 seconds.');

        $provider->generateEmail(new AiPromptPayload(prompt: 'Test prompt'));
    }

    public function test_openai_provider_handles_empty_response(): void
    {
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [
                    ['message' => ['content' => '']],
                ],
            ], 200),
        ]);

        $provider = new OpenAIEmailProvider(apiKey: 'sk-test');

        $this->expectException(AIProviderException::class);
        $this->expectExceptionMessage('Empty response content received.');

        $provider->generateEmail(new AiPromptPayload(prompt: 'Test prompt'));
    }

    public function test_openai_provider_handles_malformed_json_response(): void
    {
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [
                    ['message' => ['content' => 'not a valid json object']],
                ],
            ], 200),
        ]);

        $provider = new OpenAIEmailProvider(apiKey: 'sk-test');

        $this->expectException(AIProviderException::class);
        $this->expectExceptionMessage('Response JSON missing required "subject" field.');

        $provider->generateEmail(new AiPromptPayload(prompt: 'Test prompt'));
    }

    public function test_openai_provider_handles_500_api_error(): void
    {
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'error' => ['message' => 'The server had an error processing your request.'],
            ], 500),
        ]);

        $provider = new OpenAIEmailProvider(apiKey: 'sk-test');

        $this->expectException(AIProviderException::class);
        $this->expectExceptionMessage('API error: The server had an error processing your request.');

        $provider->generateEmail(new AiPromptPayload(prompt: 'Test prompt'));
    }

    public function test_gemini_provider_polishes_email_successfully(): void
    {
        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                [
                                    'text' => json_encode([
                                        'subject' => 'Polished by Gemini',
                                        'body_html' => '<p>Polished body from Gemini.</p>',
                                        'body_text' => 'Polished body from Gemini.',
                                    ]),
                                ],
                            ],
                        ],
                    ],
                ],
                'usageMetadata' => ['promptTokenCount' => 40, 'candidatesTokenCount' => 60, 'totalTokenCount' => 100],
            ], 200),
        ]);

        $provider = new GeminiEmailProvider(apiKey: 'gemini-key');
        $result = $provider->polishEmail(new AiPromptPayload(
            prompt: 'Polish this draft',
            roughDraft: 'rough draft text'
        ));

        $this->assertSame('Polished by Gemini', $result->subject);
        $this->assertSame('<p>Polished body from Gemini.</p>', $result->bodyHtml);
        $this->assertSame(100, $result->totalTokens);
    }

    public function test_gemini_provider_throws_on_missing_api_key(): void
    {
        $provider = new GeminiEmailProvider(apiKey: '');

        $this->expectException(AIProviderException::class);
        $this->expectExceptionMessage('Authentication failed. Please verify API key configuration.');

        $provider->generateEmail(new AiPromptPayload(prompt: 'Test prompt'));
    }

    public function test_gemini_provider_handles_rate_limiting_and_resource_exhausted(): void
    {
        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent*' => Http::response([
                'error' => [
                    'code' => 429,
                    'message' => 'Quota exceeded for quota metric',
                    'status' => 'RESOURCE_EXHAUSTED',
                ],
            ], 429),
        ]);

        $provider = new GeminiEmailProvider(apiKey: 'gemini-key');

        $this->expectException(AIProviderException::class);
        $this->expectExceptionMessage('Rate limit exceeded.');

        $provider->generateEmail(new AiPromptPayload(prompt: 'Test prompt'));
    }

    public function test_gemini_provider_handles_quota_exceeded(): void
    {
        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent*' => Http::response([
                'error' => [
                    'code' => 403,
                    'message' => 'The caller does not have permission',
                ],
            ], 403),
        ]);

        $provider = new GeminiEmailProvider(apiKey: 'gemini-key');

        $this->expectException(AIProviderException::class);
        $this->expectExceptionMessage('API quota exceeded or insufficient account balance.');

        $provider->generateEmail(new AiPromptPayload(prompt: 'Test prompt'));
    }

    public function test_gemini_provider_handles_connection_timeout(): void
    {
        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent*' => fn () => throw new ConnectionException('Connection timed out'),
        ]);

        $provider = new GeminiEmailProvider(apiKey: 'gemini-key', timeout: 10);

        $this->expectException(AIProviderException::class);
        $this->expectExceptionMessage('Request timed out after 10 seconds.');

        $provider->generateEmail(new AiPromptPayload(prompt: 'Test prompt'));
    }

    public function test_gemini_provider_handles_empty_candidates(): void
    {
        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent*' => Http::response([
                'candidates' => [],
            ], 200),
        ]);

        $provider = new GeminiEmailProvider(apiKey: 'gemini-key');

        $this->expectException(AIProviderException::class);
        $this->expectExceptionMessage('Empty candidate text returned by Gemini.');

        $provider->generateEmail(new AiPromptPayload(prompt: 'Test prompt'));
    }

    public function test_gemini_provider_handles_malformed_json_response(): void
    {
        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                ['text' => 'not json at all'],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $provider = new GeminiEmailProvider(apiKey: 'gemini-key');

        $this->expectException(AIProviderException::class);
        $this->expectExceptionMessage('Response JSON missing required "subject" field.');

        $provider->generateEmail(new AiPromptPayload(prompt: 'Test prompt'));
    }

    public function test_gemini_provider_handles_500_server_error(): void
    {
        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent*' => Http::response([
                'error' => [
                    'code' => 500,
                    'message' => 'Internal error occurred on Gemini server.',
                ],
            ], 500),
        ]);

        $provider = new GeminiEmailProvider(apiKey: 'gemini-key');

        $this->expectException(AIProviderException::class);
        $this->expectExceptionMessage('API error: Internal error occurred on Gemini server.');

        $provider->generateEmail(new AiPromptPayload(prompt: 'Test prompt'));
    }

    public function test_manager_respects_mail_forge_ai_provider_configuration(): void
    {
        Config::set('mailforge.ai.default_provider', 'openai');
        $manager = app(AIEmailManager::class);
        $this->assertInstanceOf(OpenAIEmailProvider::class, $manager->driver());

        Config::set('mailforge.ai.default_provider', 'gemini');
        $manager = new AIEmailManager(app());
        $this->assertInstanceOf(GeminiEmailProvider::class, $manager->driver());
    }

    public function test_manager_throws_on_unknown_driver(): void
    {
        $manager = app(AIEmailManager::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Driver [unsupported_provider] not supported.');

        $manager->driver('unsupported_provider');
    }
}
