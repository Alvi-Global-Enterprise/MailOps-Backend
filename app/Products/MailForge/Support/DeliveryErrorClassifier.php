<?php

declare(strict_types=1);

namespace App\Products\MailForge\Support;

use App\Products\MailForge\DTOs\DeliveryResult;

class DeliveryErrorClassifier
{
    /**
     * Permanent failure error patterns (case-insensitive).
     *
     * @var list<string>
     */
    protected const PERMANENT_PATTERNS = [
        'invalid email',
        'invalid recipient',
        'recipient address rejected',
        'address rejected',
        'user unknown',
        'mailbox unavailable',
        'mailbox not found',
        'no such user',
        'user not found',
        'domain not found',
        'dns error',
        'unauthenticated',
        'authentication failed',
        'bad credentials',
        'invalid credentials',
        'account disabled',
        'syntax error',
    ];

    /**
     * Transient failure error patterns (case-insensitive).
     *
     * @var list<string>
     */
    protected const TRANSIENT_PATTERNS = [
        'timed out',
        'timeout',
        'connection timed out',
        'connection refused',
        'connection reset',
        'could not resolve host',
        'network is unreachable',
        'service unavailable',
        'server unavailable',
        'temporary failure',
        'try again',
        'busy',
        'too many connections',
        'rate limit',
        'too many requests',
        'throttled',
        '429',
    ];

    /**
     * Determine if a delivery failure is permanent and should NOT be retried.
     */
    public static function isPermanent(DeliveryResult $result, int $attempts = 1, int $maxTries = 3): bool
    {
        if ($attempts >= $maxTries) {
            return true;
        }

        $code = trim((string) $result->responseCode);
        $message = strtolower((string) ($result->errorMessage ?? ''));

        // Check known permanent message indicators first (e.g. invalid email even under HTTP 400)
        foreach (self::PERMANENT_PATTERNS as $pattern) {
            if (str_contains($message, $pattern)) {
                return true;
            }
        }

        // SMTP 5xx permanent codes (e.g. 500, 501, 550, 554, 535)
        if (str_starts_with($code, '5')) {
            return true;
        }

        // Explicit transient message patterns
        foreach (self::TRANSIENT_PATTERNS as $pattern) {
            if (str_contains($message, $pattern)) {
                return false;
            }
        }

        // Explicit transient status codes (e.g. SMTP 421/450/451/452 or HTTP 429)
        if (str_starts_with($code, '4') || $code === '429') {
            return false;
        }

        return false;
    }

    /**
     * Determine if a delivery failure is transient (retryable).
     */
    public static function isTransient(DeliveryResult $result, int $attempts = 1, int $maxTries = 3): bool
    {
        return ! self::isPermanent($result, $attempts, $maxTries);
    }
}
