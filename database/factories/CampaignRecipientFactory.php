<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Products\MailForge\Enums\RecipientDeliveryStatus;
use App\Products\MailForge\Models\Campaign;
use App\Products\MailForge\Models\CampaignRecipient;
use App\Products\MailForge\Models\Contact;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CampaignRecipient>
 */
class CampaignRecipientFactory extends Factory
{
    protected $model = CampaignRecipient::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'campaign_id' => Campaign::factory(),
            'contact_id' => Contact::factory(),
            'email' => fake()->safeEmail(),
            'subject' => fake()->sentence(6),
            'personalized_body' => '<p>Hello, this is a personalized message.</p>',
            'status' => RecipientDeliveryStatus::DRAFT,
            'queued_at' => null,
            'sent_at' => null,
            'failed_at' => null,
            'error_message' => null,
            'attempts' => 0,
            'provider_message_id' => null,
            'custom_attributes' => [],
        ];
    }
}
