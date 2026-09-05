<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Features\Home\HomeIssueSection;
use App\Models\HomeIssue;
use App\Models\HomeIssueItem;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<HomeIssueItem>
 */
class HomeIssueItemFactory extends Factory
{
    protected $model = HomeIssueItem::class;

    /** Keeps the per-issue-and-section unique on (source_type, source_id) out of a test's way. */
    private static int $source = 0;

    /**
     * `source_id` is a bare integer, not a created model: the ledger holds references it never
     * resolves for itself, so the default has to be able to point at nothing.
     */
    public function definition(): array
    {
        return [
            'home_issue_id' => HomeIssue::factory(),
            'section' => HomeIssueSection::Stories,
            'rank' => 1,
            'source_type' => 'diary',
            'source_id' => ++self::$source,
            'score' => fake()->numberBetween(1, 1000),
            'stats' => ['comments' => 0, 'reactions' => 0],
        ];
    }

    /** Point the item at a real record, under the alias a write would have stored. */
    public function forSource(Model $model): static
    {
        return $this->state(fn (): array => [
            'source_type' => $model->getMorphClass(),
            'source_id' => $model->getKey(),
        ]);
    }
}
