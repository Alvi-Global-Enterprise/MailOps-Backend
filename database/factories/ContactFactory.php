<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use App\Products\MailForge\Enums\ContactSourceType;
use App\Products\MailForge\Models\Contact;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contact>
 */
class ContactFactory extends Factory
{
    protected $model = Contact::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $firstName = fake()->firstName();
        $lastName = fake()->lastName();

        return [
            'user_id' => User::factory(),
            'external_id' => (string) fake()->unique()->numberBetween(1000, 999999),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'full_name' => "{$firstName} {$lastName}",
            'email' => fake()->unique()->safeEmail(),
            'company' => fake()->company(),
            'job_title' => fake()->jobTitle(),
            'phone' => fake()->phoneNumber(),
            'custom_data' => [
                'department' => fake()->word(),
                'lead_score' => fake()->numberBetween(1, 100),
                'city' => fake()->city(),
            ],
            'source' => ContactSourceType::GOOGLE_SHEETS,
            'source_reference' => '1',
            'is_active' => true,
        ];
    }
}
