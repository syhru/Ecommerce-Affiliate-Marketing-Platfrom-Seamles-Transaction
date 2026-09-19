<?php

namespace Database\Factories;

use App\Models\AffiliateProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\AffiliateProfile>
 */
class AffiliateProfileFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->affiliate(),
            'referral_code' => AffiliateProfile::generateReferralCode(),
            'commission_rate' => 10.00,
            'balance' => 0,
            'total_earned' => 0,
            'status' => 'active',
        ];
    }

    /**
     * Attach this profile to an existing user instead of creating a new one.
     */
    public function forUser(User $user): static
    {
        return $this->state(fn (array $attributes) => ['user_id' => $user->id]);
    }
}
