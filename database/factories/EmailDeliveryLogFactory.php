<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Products\MailForge\Models\Campaign;
use App\Products\MailForge\Models\CampaignRecipient;
use App\Products\MailForge\Models\EmailDeliveryLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmailDeliveryLog>
 */
class EmailDeliveryLogFactory extends Factory
{
    protected $model = EmailDeliveryLog::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'campaign_recipient_id' => CampaignRecipient::factory(),
            'campaign_id' => Campaign::factory(),
            'provider' => 'smtp',
            'status' => 'sent',
            'response_code' => '250',
            'provider_response' => '250 2.0.0 OK: queued as 123456',
            'attempt' => 1,
            'created_at' => now(),
        ];
    }
}
