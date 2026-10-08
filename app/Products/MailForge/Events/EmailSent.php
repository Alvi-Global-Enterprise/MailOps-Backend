<?php

declare(strict_types=1);

namespace App\Products\MailForge\Events;

use App\Products\MailForge\DTOs\DeliveryResult;
use App\Products\MailForge\Models\CampaignRecipient;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class EmailSent
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly CampaignRecipient $recipient,
        public readonly DeliveryResult $deliveryResult
    ) {}
}
