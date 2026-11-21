<?php

namespace Database\Factories;

use App\Enums\HouseholdRole;
use App\Models\Household;
use App\Models\HouseholdInvitation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<HouseholdInvitation>
 */
class HouseholdInvitationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'email' => fake()->unique()->safeEmail(),
            'role' => HouseholdRole::Member,
            'token_hash' => HouseholdInvitation::hashToken(Str::random(40)),
            'invited_by' => User::factory(),
            'expires_at' => now()->addDays(HouseholdInvitation::VALID_DAYS),
        ];
    }

    /** Token asli yang diketahui test (hash-nya disimpan). */
    public function withToken(string $token): static
    {
        return $this->state(['token_hash' => HouseholdInvitation::hashToken($token)]);
    }

    public function expired(): static
    {
        return $this->state(['expires_at' => now()->subMinute()]);
    }

    public function revoked(): static
    {
        return $this->state(['revoked_at' => now()]);
    }

    public function accepted(): static
    {
        return $this->state(['accepted_at' => now()]);
    }
}
