<?php

namespace Database\Factories;

use App\Enums\MemberStatus;
use App\Models\Country;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Member>
 */
class MemberFactory extends Factory
{
    public function definition(): array
    {
        return [
            'member_number' => fake()->unique()->numberBetween(10000, 99999),
            'name' => fake()->name(),
            'address' => fake()->streetAddress(),
            'city' => fake()->postcode().' '.fake()->city(),
            'country_id' => Country::firstOrCreate(['name' => fake()->randomElement(['FRANCE', 'GERMANY', 'JAPAN', 'USA', 'MOROCCO'])])->id,
            'email' => fake()->unique()->safeEmail(),
            'interest_themes' => 'birds, ships',
            'status' => MemberStatus::Active,
        ];
    }

    public function om(string $code = 'XYZ'): static
    {
        return $this->state(['is_om' => true, 'om_code' => $code]);
    }

    public function withoutEmail(): static
    {
        return $this->state(['email' => null]);
    }
}
