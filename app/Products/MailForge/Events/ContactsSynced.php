<?php

declare(strict_types=1);

namespace App\Products\MailForge\Events;

use App\Products\MailForge\DTOs\SheetSyncResult;
use App\Products\MailForge\Models\GoogleSheetIntegration;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ContactsSynced
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly GoogleSheetIntegration $integration,
        public readonly SheetSyncResult $result,
        public readonly int $insertedCount,
        public readonly int $updatedCount
    ) {}
}
