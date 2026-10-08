<?php

declare(strict_types=1);

namespace App\Products\MailForge\Exceptions;

use Exception;
use Throwable;

class AIProviderException extends Exception
{
    public function __construct(
        string $message,
        public readonly string $provider = 'unknown',
        public readonly int $statusCode = 500,
        ?Throwable $previous = null
    ) {
        parent::__construct("[{$provider}] {$message}", $statusCode, $previous);
    }

    public static function authenticationFailed(string $provider, ?Throwable $previous = null): self
    {
        return new self('Authentication failed. Please verify API key configuration.', $provider, 401, $previous);
    }

    public static function rateLimited(string $provider, ?string $retryAfter = null, ?Throwable $previous = null): self
    {
        $message = 'Rate limit exceeded.';
        if ($retryAfter) {
            $message .= " Retry after {$retryAfter} seconds.";
        }

        return new self($message, $provider, 429, $previous);
    }

    public static function quotaExceeded(string $provider, ?Throwable $previous = null): self
    {
        return new self('API quota exceeded or insufficient account balance.', $provider, 402, $previous);
    }

    public static function timeout(string $provider, int $seconds, ?Throwable $previous = null): self
    {
        return new self("Request timed out after {$seconds} seconds.", $provider, 504, $previous);
    }

    public static function invalidResponse(string $provider, string $details = '', ?Throwable $previous = null): self
    {
        return new self("Received invalid or unparseable response from provider. {$details}", $provider, 502, $previous);
    }

    public static function apiError(string $provider, string $message, int $statusCode = 500, ?Throwable $previous = null): self
    {
        return new self("API error: {$message}", $provider, $statusCode, $previous);
    }
}
