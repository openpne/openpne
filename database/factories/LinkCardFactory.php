<?php

declare(strict_types=1);

namespace Database\Factories;

use App\LinkCard\LinkUrl;
use App\Models\LinkCard;
use App\Support\LinkCardStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LinkCard>
 */
class LinkCardFactory extends Factory
{
    protected $model = LinkCard::class;

    public function definition(): array
    {
        $url = 'https://example.com/'.fake()->unique()->slug();

        return [
            'url_hash' => LinkUrl::hash($url),
            'url' => $url,
            'status' => LinkCardStatus::Ok,
            'title' => fake()->sentence(),
            'description' => fake()->paragraph(),
            'site_name' => fake()->domainName(),
            'fetched_at' => now(),
            'expires_at' => now()->addWeek(),
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (): array => [
            'status' => LinkCardStatus::Pending,
            'title' => null,
            'description' => null,
            'site_name' => null,
            'fetched_at' => null,
            'expires_at' => null,
        ]);
    }

    public function failed(int $failureCount = 1): static
    {
        return $this->state(fn (): array => [
            'status' => LinkCardStatus::Failed,
            'title' => null,
            'description' => null,
            'failure_count' => $failureCount,
            'fetched_at' => now(),
            'expires_at' => null,
            'next_attempt_at' => now()->addHours(2 ** $failureCount),
        ]);
    }

    public function stale(): static
    {
        return $this->state(fn (): array => ['expires_at' => now()->subDay()]);
    }
}
