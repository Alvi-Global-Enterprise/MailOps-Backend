<?php

declare(strict_types=1);

namespace App\Products\MailForge\Enums;

enum CampaignStatus: string
{
    case DRAFT = 'draft';
    case GENERATING = 'generating';
    case REVIEW = 'review';
    case APPROVED = 'approved';
    case QUEUED = 'queued';
    case SENDING = 'sending';
    case COMPLETED = 'completed';
    case PAUSED = 'paused';
    case CANCELLED = 'cancelled';
    case FAILED = 'failed';

    public function canEdit(): bool
    {
        return in_array($this, [self::DRAFT, self::REVIEW], true);
    }

    public function canApprove(): bool
    {
        return in_array($this, [self::DRAFT, self::REVIEW], true);
    }

    public function canDispatch(): bool
    {
        return $this === self::APPROVED;
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::COMPLETED, self::CANCELLED, self::FAILED], true);
    }
}
