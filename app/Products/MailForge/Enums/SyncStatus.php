<?php

declare(strict_types=1);

namespace App\Products\MailForge\Enums;

enum SyncStatus: string
{
    case IDLE = 'idle';
    case SYNCING = 'syncing';
    case COMPLETED = 'completed';
    case FAILED = 'failed';

    public function isInProgress(): bool
    {
        return $this === self::SYNCING;
    }
}
