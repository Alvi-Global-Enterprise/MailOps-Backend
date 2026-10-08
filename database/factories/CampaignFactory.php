<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use App\Products\MailForge\Enums\AiProviderType;
use App\Products\MailForge\Enums\CampaignStatus;
use App\Products\MailForge\Enums\EmailMode;
use App\Products\MailForge\Models\Campaign;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Campaign>
 */
class CampaignFactory extends Factory
{
    protected $model = Campaign::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->catchPhrase().' Campaign',
            'subject' => fake()->sentence(6),
            'source_type' => 'all_contacts',
            'source_reference' => null,
            'email_mode' => EmailMode::GENERATE,
            'ai_provider' => AiProviderType::OPENAI,
            'ai_prompt' => 'Write a professional cold email offering our B2B software solution.',
            'raw_input_email' => null,
            'email_body' => '<p>Hello {{first_name}}, we would love to connect with {{company}}.</p>',
            'email_body_text' => 'Hello {{first_name}}, we would love to connect with {{company}}.',
            'status' => CampaignStatus::DRAFT,
            'scheduled_at' => null,
            'approved_at' => null,
            'started_at' => null,
            'completed_at' => null,
            'total_recipients' => 0,
            'sent_count' => 0,
            'failed_count' => 0,
        ];
    }
}
