<?php

declare(strict_types=1);

namespace App\Products\MailForge\Enums;

enum RecipientDeliveryStatus: string
{
    case DRAFT = 'draft';
    case QUEUED = 'queued';
    case PROCESSING = 'processing';
    case SENT = 'sent';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';

    public function isTerminal(): bool
    {
        return in_array($this, [self::SENT, self::FAILED, self::CANCELLED], true);
    }

    public function canRetry(): bool
    {
        return $this === self::FAILED;
    }
}
