<?php

namespace Database\Factories;

use App\Models\Member;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<Member>
 */
class MemberFactory extends Factory
{
    protected static ?string $password;

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

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * An AI account: owned by $owner (one is made when none is given) and credential-less, the only
     * shape the members table admits for an owned row. The owner is resolved once, so a `count()`
     * batch shares it.
     */
    public function aiAccount(?Member $owner = null): static
    {
        return $this->state(function (array $attributes) use (&$owner): array {
            $owner ??= Member::factory()->create();

            return [
                'owner_member_id' => $owner->getKey(),
                'email' => null,
                'email_verified_at' => null,
                'password' => null,
            ];
        });
    }
}
