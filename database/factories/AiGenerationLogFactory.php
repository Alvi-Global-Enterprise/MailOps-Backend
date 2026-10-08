<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use App\Products\MailForge\Models\AiGenerationLog;
use App\Products\MailForge\Models\Campaign;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiGenerationLog>
 */
class AiGenerationLogFactory extends Factory
{
    protected $model = AiGenerationLog::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'campaign_id' => Campaign::factory(),
            'user_id' => User::factory(),
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'operation' => 'generate',
            'prompt_tokens' => 120,
            'completion_tokens' => 250,
            'total_tokens' => 370,
            'duration_ms' => 850,
            'status' => 'success',
            'error_message' => null,
            'raw_prompt' => 'Write a sales outreach email.',
            'raw_response' => '{"subject": "Hello", "body": "Welcome"}',
            'created_at' => now(),
        ];
    }
}
