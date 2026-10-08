<?php

declare(strict_types=1);

namespace App\Products\MailForge\Events;

use App\Products\MailForge\Models\Campaign;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CampaignDispatched
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Campaign $campaign,
        public readonly int $recipientCount
    ) {}
}
