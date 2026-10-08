<?php

declare(strict_types=1);

namespace App\Products\MailForge\Services\AI\Providers;

use App\Products\MailForge\Contracts\AIEmailProviderInterface;
use App\Products\MailForge\DTOs\AiGeneratedEmail;
use App\Products\MailForge\DTOs\AiPromptPayload;
use App\Products\MailForge\Exceptions\AIProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class OpenAIEmailProvider implements AIEmailProviderInterface
{
    protected string $baseUrl = 'https://api.openai.com/v1';

    public function __construct(
        protected ?string $apiKey = null,
        protected string $model = 'gpt-4o-mini',
        protected ?string $organization = null,
        protected int $timeout = 30
    ) {
        $this->apiKey = $apiKey !== null ? $apiKey : (string) config('mailforge.ai.providers.openai.api_key');
        $this->model = $model ?: (string) config('mailforge.ai.providers.openai.model', 'gpt-4o-mini');
        $this->organization = $organization ?: config('mailforge.ai.providers.openai.organization');
        $this->timeout = $timeout ?: (int) config('mailforge.ai.providers.openai.timeout', 30);
    }

    public function getName(): string
    {
        return 'openai';
    }

    public function generateEmail(AiPromptPayload $payload): AiGeneratedEmail
    {
        $systemPrompt = $this->buildSystemPrompt('Generate an engaging, professional email according to the user instructions.');
        $userPrompt = "Instructions: {$payload->prompt}\nTone: {$payload->tone}";

        if ($payload->targetAudience) {
            $userPrompt .= "\nTarget Audience: {$payload->targetAudience}";
        }
        if ($payload->keyPoints) {
            $userPrompt .= "\nKey Points to include: {$payload->keyPoints}";
        }

        return $this->sendChatCompletion($systemPrompt, $userPrompt, $payload);
    }

    public function polishEmail(AiPromptPayload $payload): AiGeneratedEmail
    {
        $systemPrompt = $this->buildSystemPrompt('Polish and refine the provided rough draft email into a polished, professional email.');
        $userPrompt = "Rough Draft:\n".($payload->roughDraft ?: $payload->prompt)."\n\nDesired Tone: {$payload->tone}";

        if ($payload->keyPoints) {
            $userPrompt .= "\nAdditional Notes: {$payload->keyPoints}";
        }

        return $this->sendChatCompletion($systemPrompt, $userPrompt, $payload);
    }

    public function rewriteEmail(AiPromptPayload $payload): AiGeneratedEmail
    {
        $systemPrompt = $this->buildSystemPrompt('Rewrite the provided email with an alternative perspective and improved persuasive flow.');
        $userPrompt = "Original Email:\n".($payload->roughDraft ?: $payload->prompt)."\n\nNew Tone/Angle: {$payload->tone}";

        return $this->sendChatCompletion($systemPrompt, $userPrompt, $payload);
    }

    /**
     * Send chat completion request to OpenAI API.
     */
    protected function sendChatCompletion(string $systemPrompt, string $userPrompt, AiPromptPayload $payload): AiGeneratedEmail
    {
        if (empty($this->apiKey)) {
            throw AIProviderException::authenticationFailed('openai');
        }

        $activeModel = $payload->options['model'] ?? $this->model;
        $temperature = (float) ($payload->options['temperature'] ?? config('mailforge.ai.temperature', 0.7));
        $maxTokens = (int) ($payload->options['max_tokens'] ?? config('mailforge.ai.max_tokens', 1500));

        $headers = [
            'Authorization' => "Bearer {$this->apiKey}",
            'Content-Type' => 'application/json',
        ];

        if (! empty($this->organization)) {
            $headers['OpenAI-Organization'] = $this->organization;
        }

        $startTime = microtime(true);

        try {
            /** @var Response $response */
            $response = Http::withHeaders($headers)
                ->timeout($this->timeout)
                ->post("{$this->baseUrl}/chat/completions", [
                    'model' => $activeModel,
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => $userPrompt],
                    ],
                    'response_format' => ['type' => 'json_object'],
                    'temperature' => $temperature,
                    'max_tokens' => $maxTokens,
                ]);
        } catch (ConnectionException $e) {
            Log::error('OpenAI API request timed out or connection error', [
                'provider' => 'openai',
                'model' => $activeModel,
            ]);

            throw AIProviderException::timeout('openai', $this->timeout, $e);
        } catch (Throwable $e) {
            Log::error('OpenAI API client error', [
                'provider' => 'openai',
                'error' => $e->getMessage(),
            ]);

            throw AIProviderException::apiError('openai', $e->getMessage(), 500, $e);
        }

        $durationMs = (int) round((microtime(true) - $startTime) * 1000);

        if (! $response->successful()) {
            $this->handleApiErrorResponse($response, $activeModel);
        }

        $responseData = $response->json();
        $content = $responseData['choices'][0]['message']['content'] ?? null;

        if (empty($content)) {
            throw AIProviderException::invalidResponse('openai', 'Empty response content received.');
        }

        $parsed = $this->parseJsonContent($content);

        $usage = $responseData['usage'] ?? [];
        $promptTokens = (int) ($usage['prompt_tokens'] ?? 0);
        $completionTokens = (int) ($usage['completion_tokens'] ?? 0);
        $totalTokens = (int) ($usage['total_tokens'] ?? ($promptTokens + $completionTokens));

        Log::info('OpenAI email generation successful', [
            'provider' => 'openai',
            'model' => $activeModel,
            'duration_ms' => $durationMs,
            'total_tokens' => $totalTokens,
        ]);

        return new AiGeneratedEmail(
            subject: $parsed['subject'],
            bodyHtml: $parsed['body_html'],
            bodyText: $parsed['body_text'],
            provider: 'openai',
            model: $activeModel,
            promptTokens: $promptTokens,
            completionTokens: $completionTokens,
            totalTokens: $totalTokens,
            durationMs: $durationMs,
            rawResponse: $responseData
        );
    }

    /**
     * Parse and sanitize the JSON string returned from the model.
     *
     * @return array{subject: string, body_html: string, body_text: string}
     */
    protected function parseJsonContent(string $rawContent): array
    {
        $clean = trim($rawContent);

        // Strip markdown code block wrapping if present
        if (str_starts_with($clean, '```')) {
            $clean = (string) preg_replace('/^```(?:json)?\s*/i', '', $clean);
            $clean = (string) preg_replace('/\s*```$/', '', $clean);
            $clean = trim($clean);
        }

        $data = json_decode($clean, true);

        if (! is_array($data) || empty($data['subject'])) {
            throw AIProviderException::invalidResponse('openai', 'Response JSON missing required "subject" field.');
        }

        $subject = trim((string) $data['subject']);
        $bodyHtml = trim((string) ($data['body_html'] ?? $data['body'] ?? ''));
        $bodyText = trim((string) ($data['body_text'] ?? strip_tags($bodyHtml)));

        if (empty($bodyHtml)) {
            $bodyHtml = '<p>'.nl2br(htmlspecialchars($bodyText)).'</p>';
        }

        return [
            'subject' => $subject,
            'body_html' => $bodyHtml,
            'body_text' => $bodyText,
        ];
    }

    /**
     * Map HTTP error codes to structured AIProviderException.
     */
    protected function handleApiErrorResponse(Response $response, string $model): never
    {
        $status = $response->status();
        $error = $response->json('error') ?? [];
        $errorMessage = $error['message'] ?? $response->body();
        $errorCode = $error['code'] ?? '';

        Log::warning('OpenAI API error response', [
            'provider' => 'openai',
            'status' => $status,
            'error_code' => $errorCode,
            'error_message' => $errorMessage,
        ]);

        if ($status === 401) {
            throw AIProviderException::authenticationFailed('openai');
        }

        if ($status === 429) {
            if ($errorCode === 'insufficient_quota') {
                throw AIProviderException::quotaExceeded('openai');
            }

            $retryAfter = $response->header('Retry-After');
            throw AIProviderException::rateLimited('openai', $retryAfter);
        }

        throw AIProviderException::apiError('openai', $errorMessage, $status);
    }

    /**
     * System instructions guaranteeing structured JSON response.
     */
    protected function buildSystemPrompt(string $roleDescription): string
    {
        return <<<PROMPT
{$roleDescription}
You are an expert copywriter. Output MUST be a strictly valid JSON object with EXACTLY the following keys:
{
  "subject": "Compelling, concise subject line",
  "body_html": "<p>Well-formatted HTML email body with paragraphs and line breaks</p>",
  "body_text": "Plain text email fallback"
}
Do NOT include markdown formatting like ```json outside the JSON object. Return ONLY raw valid JSON.
PROMPT;
    }
}
