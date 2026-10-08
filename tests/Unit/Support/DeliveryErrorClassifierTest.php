<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Products\MailForge\DTOs\DeliveryResult;
use App\Products\MailForge\Support\DeliveryErrorClassifier;
use PHPUnit\Framework\TestCase;

class DeliveryErrorClassifierTest extends TestCase
{
    public function test_smtp_5xx_and_permanent_messages_are_classified_as_permanent(): void
    {
        // 550 User unknown
        $res1 = DeliveryResult::failed('smtp', '550 Requested action not taken: mailbox unavailable', '550');
        $this->assertTrue(DeliveryErrorClassifier::isPermanent($res1, attempts: 1, maxTries: 3));
        $this->assertFalse(DeliveryErrorClassifier::isTransient($res1, attempts: 1, maxTries: 3));

        // 535 Auth error
        $res2 = DeliveryResult::failed('smtp', '535 Authentication credentials invalid', '535');
        $this->assertTrue(DeliveryErrorClassifier::isPermanent($res2, attempts: 1, maxTries: 3));

        // Text-based permanent pattern with generic code
        $res3 = DeliveryResult::failed('mailgun', 'Recipient address rejected: invalid email address', '400');
        $this->assertTrue(DeliveryErrorClassifier::isPermanent($res3, attempts: 1, maxTries: 3));
    }

    public function test_smtp_4xx_and_rate_limits_are_classified_as_transient(): void
    {
        // 421 Service unavailable
        $res1 = DeliveryResult::failed('smtp', '421 Service not available, closing transmission channel', '421');
        $this->assertFalse(DeliveryErrorClassifier::isPermanent($res1, attempts: 1, maxTries: 3));
        $this->assertTrue(DeliveryErrorClassifier::isTransient($res1, attempts: 1, maxTries: 3));

        // HTTP 429 Rate limited
        $res2 = DeliveryResult::failed('sendgrid', 'Too many requests, rate limit exceeded', '429');
        $this->assertFalse(DeliveryErrorClassifier::isPermanent($res2, attempts: 1, maxTries: 3));
        $this->assertTrue(DeliveryErrorClassifier::isTransient($res2, attempts: 1, maxTries: 3));

        // Connection timeout
        $res3 = DeliveryResult::failed('smtp', 'Connection timed out after 30 seconds', '0');
        $this->assertFalse(DeliveryErrorClassifier::isPermanent($res3, attempts: 1, maxTries: 3));
        $this->assertTrue(DeliveryErrorClassifier::isTransient($res3, attempts: 1, maxTries: 3));
    }

    public function test_max_attempts_exhausted_becomes_permanent(): void
    {
        $res = DeliveryResult::failed('smtp', 'Connection timed out', '0');

        // On attempt 1 or 2, transient
        $this->assertFalse(DeliveryErrorClassifier::isPermanent($res, attempts: 1, maxTries: 3));
        $this->assertFalse(DeliveryErrorClassifier::isPermanent($res, attempts: 2, maxTries: 3));

        // On attempt 3 of 3, permanent
        $this->assertTrue(DeliveryErrorClassifier::isPermanent($res, attempts: 3, maxTries: 3));
    }
}
