<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\Member;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    public function admin(): static
    {
        return $this->state(['roles' => [Role::Admin]]);
    }

    /** An Originating Member account, with its OM member record. */
    public function om(string $code = 'HAE'): static
    {
        return $this->state(fn () => [
            'roles' => [Role::OriginatingMember],
            'member_id' => Member::factory()->om($code)->create()->id,
        ]);
    }

    /** A plain member account, with its member record. */
    public function member(): static
    {
        return $this->state(fn () => ['member_id' => Member::factory()->create()->id]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
