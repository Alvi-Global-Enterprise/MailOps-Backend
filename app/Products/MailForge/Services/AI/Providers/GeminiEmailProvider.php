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

class GeminiEmailProvider implements AIEmailProviderInterface
{
    protected string $baseUrl = 'https://generativelanguage.googleapis.com/v1beta';

    public function __construct(
        protected ?string $apiKey = null,
        protected string $model = 'gemini-3.5-flash',
        protected int $timeout = 30
    ) {
        $this->apiKey = $apiKey !== null ? $apiKey : (string) config('mailforge.ai.providers.gemini.api_key');
        $this->model = $model ?: (string) config('mailforge.ai.providers.gemini.model', 'gemini-3.5-flash');
        $this->timeout = $timeout ?: (int) config('mailforge.ai.providers.gemini.timeout', 30);
    }

    public function getName(): string
    {
        return 'gemini';
    }

    public function generateEmail(AiPromptPayload $payload): AiGeneratedEmail
    {
        $instructions = "You are a professional email copywriter. Generate an engaging, professional email according to the instructions.\n"
            ."Instructions: {$payload->prompt}\nTone: {$payload->tone}";

        if ($payload->targetAudience) {
            $instructions .= "\nTarget Audience: {$payload->targetAudience}";
        }
        if ($payload->keyPoints) {
            $instructions .= "\nKey Points: {$payload->keyPoints}";
        }

        return $this->sendGenerateContent($instructions, $payload);
    }

    public function polishEmail(AiPromptPayload $payload): AiGeneratedEmail
    {
        $instructions = "You are an expert copy editor. Polish and refine this rough draft email into a high-converting, professional email.\n"
            ."Draft:\n".($payload->roughDraft ?: $payload->prompt)."\n\nTone: {$payload->tone}";

        if ($payload->keyPoints) {
            $instructions .= "\nAdditional Notes: {$payload->keyPoints}";
        }

        return $this->sendGenerateContent($instructions, $payload);
    }

    public function rewriteEmail(AiPromptPayload $payload): AiGeneratedEmail
    {
        $instructions = "You are an expert copywriter. Rewrite this email with an alternative perspective and improved flow.\n"
            ."Original Email:\n".($payload->roughDraft ?: $payload->prompt)."\n\nNew Tone/Angle: {$payload->tone}";

        return $this->sendGenerateContent($instructions, $payload);
    }

    /**
     * Send prompt request to Google Gemini REST API.
     */
    protected function sendGenerateContent(string $fullPrompt, AiPromptPayload $payload): AiGeneratedEmail
    {
        if (empty($this->apiKey)) {
            throw AIProviderException::authenticationFailed('gemini');
        }

        $primaryModel = $payload->options['model'] ?? $this->model;
        $candidateModels = array_unique([$primaryModel, 'gemini-3.5-flash', 'gemini-3.1-flash-lite']);
        $temperature = (float) ($payload->options['temperature'] ?? config('mailforge.ai.temperature', 0.7));
        $maxTokens = (int) ($payload->options['max_tokens'] ?? config('mailforge.ai.max_tokens', 1500));

        $systemInstruction = <<<'PROMPT'
Output MUST be a strictly valid JSON object with EXACTLY the following keys:
{
  "subject": "Compelling subject line",
  "body_html": "<p>Formatted HTML email body with paragraphs</p>",
  "body_text": "Plain text email body"
}
Return ONLY valid JSON.
PROMPT;

        $requestBody = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => "{$systemInstruction}\n\n{$fullPrompt}"],
                    ],
                ],
            ],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'temperature' => $temperature,
                'maxOutputTokens' => $maxTokens,
                'thinkingConfig' => [
                    'thinkingBudget' => 0,
                ],
            ],
        ];

        $startTime = microtime(true);
        $response = null;
        $activeModel = $primaryModel;
        $lastException = null;

        foreach ($candidateModels as $model) {
            $activeModel = $model;
            $endpoint = "{$this->baseUrl}/models/{$activeModel}:generateContent?key={$this->apiKey}";

            try {
                /** @var Response $response */
                $response = Http::timeout($this->timeout)
                    ->withHeaders(['Content-Type' => 'application/json'])
                    ->post($endpoint, $requestBody);

                if ($response->successful()) {
                    break;
                }

                $statusCode = $response->status();
                $errorMsg = (string) ($response->json('error.message') ?? '');

                // If high demand (503), rate limit (429), or temporary spike, try next model
                if ($statusCode === 503 || $statusCode === 429 || str_contains(strtolower($errorMsg), 'demand')) {
                    Log::warning("Gemini model [{$activeModel}] experiencing high demand or rate limit. Trying fallback...", [
                        'provider' => 'gemini',
                        'failed_model' => $activeModel,
                        'status' => $statusCode,
                        'message' => $errorMsg,
                    ]);
                    if ($statusCode === 429) {
                        sleep(2);
                    }
                    continue;
                }

                // If non-transient error (e.g. invalid key), fail immediately
                $this->handleApiErrorResponse($response, $activeModel);
            } catch (ConnectionException $e) {
                Log::warning("Gemini model [{$activeModel}] connection timeout. Trying fallback...", [
                    'provider' => 'gemini',
                    'failed_model' => $activeModel,
                ]);
                $lastException = $e;
                continue;
            } catch (Throwable $e) {
                if ($e instanceof AIProviderException) {
                    throw $e;
                }
                $lastException = $e;
            }
        }

        $durationMs = (int) round((microtime(true) - $startTime) * 1000);

        if (! $response || ! $response->successful()) {
            if ($lastException instanceof ConnectionException) {
                throw AIProviderException::timeout('gemini', $this->timeout, $lastException);
            }
            if ($response) {
                $this->handleApiErrorResponse($response, $activeModel);
            }

            throw AIProviderException::apiError('gemini', $lastException?->getMessage() ?? 'All Gemini model candidates failed.', 500);
        }

        $responseData = $response->json();
        $candidates = $responseData['candidates'] ?? [];
        $parts = $candidates[0]['content']['parts'] ?? [];

        $rawText = null;
        foreach ($parts as $part) {
            if (! empty($part['text'])) {
                $rawText = $part['text'];
                break;
            }
        }

        if (empty($rawText)) {
            throw AIProviderException::invalidResponse('gemini', 'Empty candidate text returned by Gemini.');
        }

        $parsed = $this->parseJsonContent($rawText);

        $usage = $responseData['usageMetadata'] ?? [];
        $promptTokens = (int) ($usage['promptTokenCount'] ?? 0);
        $completionTokens = (int) ($usage['candidatesTokenCount'] ?? 0);
        $totalTokens = (int) ($usage['totalTokenCount'] ?? ($promptTokens + $completionTokens));

        Log::info('Google Gemini email generation successful', [
            'provider' => 'gemini',
            'model' => $activeModel,
            'duration_ms' => $durationMs,
            'total_tokens' => $totalTokens,
        ]);

        return new AiGeneratedEmail(
            subject: $parsed['subject'],
            bodyHtml: $parsed['body_html'],
            bodyText: $parsed['body_text'],
            provider: 'gemini',
            model: $activeModel,
            promptTokens: $promptTokens,
            completionTokens: $completionTokens,
            totalTokens: $totalTokens,
            durationMs: $durationMs,
            rawResponse: $responseData
        );
    }

    /**
     * Parse and sanitize the JSON string returned from Gemini.
     *
     * @return array{subject: string, body_html: string, body_text: string}
     */
    protected function parseJsonContent(string $rawContent): array
    {
        $clean = trim($rawContent);

        if (preg_match('/```(?:json)?\s*([\s\S]*?)\s*```/i', $clean, $matches)) {
            $clean = trim($matches[1]);
        } elseif (str_starts_with($clean, '```')) {
            $clean = (string) preg_replace('/^```(?:json)?\s*/i', '', $clean);
            $clean = (string) preg_replace('/\s*```$/', '', $clean);
            $clean = trim($clean);
        }

        $data = json_decode($clean, true);

        if (! is_array($data)) {
            $start = strpos($clean, '{');
            $end = strrpos($clean, '}');
            if ($start !== false && $end !== false && $end > $start) {
                $sub = substr($clean, $start, $end - $start + 1);
                $data = json_decode($sub, true);
            }
        }

        if (! is_array($data) || empty($data['subject'])) {
            throw AIProviderException::invalidResponse('gemini', 'Response JSON missing required "subject" field.');
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
     * Map Gemini HTTP error codes to structured AIProviderException.
     */
    protected function handleApiErrorResponse(Response $response, string $model): never
    {
        $status = $response->status();
        $error = $response->json('error') ?? [];
        $errorMessage = $error['message'] ?? $response->body();
        $errorStatus = $error['status'] ?? '';

        Log::warning('Google Gemini API error response', [
            'provider' => 'gemini',
            'status' => $status,
            'error_status' => $errorStatus,
            'error_message' => $errorMessage,
        ]);

        if ($status === 400 && str_contains(strtolower($errorMessage), 'api key')) {
            throw AIProviderException::authenticationFailed('gemini');
        }

        if ($status === 403) {
            throw AIProviderException::quotaExceeded('gemini');
        }

        if ($status === 429 || $errorStatus === 'RESOURCE_EXHAUSTED') {
            throw AIProviderException::rateLimited('gemini');
        }

        throw AIProviderException::apiError('gemini', $errorMessage, $status);
    }
}
