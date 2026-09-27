<?php

declare(strict_types=1);

namespace Tests\Feature\Home\Actions;

use App\Features\Home\Actions\PublishHomeIssue;
use App\Features\Home\Data\HomeIssueDay;
use App\Features\Home\Data\HomeIssuePlan;
use App\Features\Home\Data\HomeIssueWindow;
use App\Features\Home\Data\PlannedItem;
use App\Features\Home\Data\SourceRef;
use App\Features\Home\HomeIssueSection;
use App\Models\Group;
use App\Models\GroupMessage;
use App\Models\HomeIssue;
use App\Models\HomeIssueItem;
use App\Models\Member;
use App\Models\TimelinePost;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\ConcurrencyErrorDetector;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PDOException;
use Tests\TestCase;
use Throwable;

/**
 * `$now` is always passed rather than read, because it is the only clock in the design: the window
 * closes on it, the issue is dated by it, and the calendar looks forward from it.
 */
abstract class PublishHomeIssueTestCase extends TestCase
{
    use RefreshDatabase;

    protected const NOW = '2026-08-27 06:00:00';

    /** Whether {@see raceInARivalIssue} actually fired — an assertion, not bookkeeping. */
    protected bool $raced = false;

    protected function now(): CarbonImmutable
    {
        return CarbonImmutable::parse(self::NOW);
    }

    protected function action(): PublishHomeIssue
    {
        return app(PublishHomeIssue::class);
    }

    protected function publish(?CarbonImmutable $now = null, ?SourceRef $pin = null, ?HomeIssueWindow $window = null): ?HomeIssue
    {
        return ($this->action())($now ?? $this->now(), $pin, $window);
    }

    /** Run $make as if it were $when, so every row it writes is stamped there. */
    protected function at(CarbonImmutable $when, callable $make): mixed
    {
        Carbon::setTestNow($when);

        try {
            return $make();
        } finally {
            Carbon::setTestNow();
        }
    }

    /** An issue that closed at $publishedAt, dated the way the publisher would have dated it. */
    protected function previousIssue(CarbonImmutable $publishedAt, int $number = 1): HomeIssue
    {
        return HomeIssue::factory()->create([
            'number' => $number,
            'issue_date' => HomeIssueDay::of($publishedAt->subSecond())->toDateString(),
            'window_start' => $publishedAt->subDay(),
            'published_at' => $publishedAt,
        ]);
    }

    protected function postWithReplies(int $replies): TimelinePost
    {
        $post = TimelinePost::factory()->create();
        TimelinePost::factory()->count($replies)->replyTo($post)->create();

        return $post;
    }

    /** $count messages in $group, all written at $when. */
    protected function burst(Group $group, CarbonImmutable $when, int $count = 3): void
    {
        $this->at($when, fn () => GroupMessage::factory()->count($count)->for($group)->create());
    }

    /**
     * Publish a rival issue after the run's "is it published?" check and before its transaction,
     * which is the only window the DB unique has to cover. Driven from inside the run because the
     * suite has one connection.
     *
     * @param  (callable(): void)|null  $then  runs once the rival is in
     */
    protected function raceInARivalIssue(?callable $then = null): void
    {
        $this->raced = false;

        Member::retrieved(function () use ($then): void {
            if ($this->raced) {
                return;
            }
            $this->raced = true;

            DB::table('home_issues')->insert([
                'number' => 999,
                // The day this run is about to date its issue to, written the way the model writes
                // it so the unique sees one value and not two spellings.
                'issue_date' => HomeIssueDay::of($this->now()->subSecond()),
                'window_start' => $this->now()->subDay(),
                'published_at' => $this->now(),
                'created_at' => $this->now(),
                'updated_at' => $this->now(),
            ]);

            if ($then !== null) {
                $then();
            }
        });
    }

    /** Refuse the run's insert into `home_issues` the way SQLite refuses a writer it cannot serialize. */
    protected function failTheWrite(): void
    {
        $thrown = false;

        DB::beforeExecuting(function (string $query, array $bindings, Connection $connection) use (&$thrown): void {
            if ($thrown || ! str_contains($query, 'insert into') || ! str_contains($query, 'home_issues')) {
                return;
            }
            $thrown = true;

            throw new QueryException(
                $connection->getName(),
                $query,
                $bindings,
                new PDOException('SQLSTATE[HY000]: General error: 5 database is locked'),
            );
        });
    }

    /**
     * RefreshDatabase runs each test inside a transaction, so the publisher's is nested — and the
     * framework answers a concurrency error in a nested one with a DeadlockException instead of
     * retrying (ManagesTransactions::handleTransactionException). Switched off, the connection takes
     * the path an unnested one reaches once its last attempt has failed.
     */
    protected function concurrencyDetectionOff(): void
    {
        $this->app->instance(ConcurrencyErrorDetector::class, new class implements ConcurrencyErrorDetector
        {
            public function causedByConcurrencyError(Throwable $e): bool
            {
                return false;
            }
        });
    }

    protected function react(GroupMessage $message, int $count): void
    {
        // Reactions have no factory, and the unique key is (content, member, emoji).
        foreach (Member::factory()->count($count)->create() as $member) {
            DB::table('reactions')->insert([
                'reactable_type' => $message->getMorphClass(),
                'reactable_id' => $message->getKey(),
                'member_id' => $member->id,
                'emoji' => '👍',
                'created_at' => $this->now(),
                'updated_at' => $this->now(),
            ]);
        }
    }

    protected function ref(object $model): string
    {
        return SourceRef::of($model)->key();
    }

    /** @return list<string> the section's planned sources, in rank order */
    protected function planned(HomeIssuePlan $plan, HomeIssueSection $section): array
    {
        return array_map(fn (PlannedItem $item): string => $item->ref()->key(), $plan->items($section));
    }

    /** @return list<string> the section's sources, in rank order */
    protected function refs(HomeIssue $issue, HomeIssueSection $section): array
    {
        return $issue->items()
            ->where('section', $section)
            ->orderBy('rank')
            ->get()
            ->map(fn (HomeIssueItem $item): string => $item->source_type.':'.$item->source_id)
            ->all();
    }

    protected function item(HomeIssue $issue, HomeIssueSection $section, string $ref): HomeIssueItem
    {
        [$type, $id] = explode(':', $ref);

        $item = $issue->items()
            ->where('section', $section)
            ->where('source_type', $type)
            ->where('source_id', (int) $id)
            ->first();

        $this->assertNotNull($item, "{$section->value} does not hold {$ref}");

        return $item;
    }
}
