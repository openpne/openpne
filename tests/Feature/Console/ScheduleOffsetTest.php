<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Console\ScheduleOffset;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Tests\TestCase;

final class ScheduleOffsetTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SERVER[ScheduleOffset::ENV], $_ENV[ScheduleOffset::ENV]);
        putenv(ScheduleOffset::ENV);

        parent::tearDown();
    }

    public function test_every_registered_task_moves_by_the_offset(): void
    {
        $this->bootWithOffset('1430');

        $this->assertSame([
            'model:prune' => '50 23 * * *',
            'openpne:prune-oauth' => '50 23 * * *',
            'openpne:prune-link-cards' => '0 3 * * 1',
            'openpne:publish-home-issue' => '50 5 * * *',
        ], $this->expressions());
    }

    public function test_a_bad_offset_stops_the_scheduler_and_nothing_else(): void
    {
        $this->bootWithOffset('7');
        $this->assertSame([], $this->expressions());

        // The kernel leaves Symfony's command events unrouted under test; a deployment always routes them.
        $this->app->make(Kernel::class)->rerouteSymfonyCommandEvents();

        $this->artisan('list')->assertSuccessful();

        $this->expectExceptionMessage(ScheduleOffset::ENV.' must be a whole number of minutes');
        $this->artisan('schedule:list')->run();
    }

    /**
     * The schedule is registered when the app is built, so the env goes in before a rebuild
     * rather than through config() afterwards.
     */
    private function bootWithOffset(string $minutes): void
    {
        $_SERVER[ScheduleOffset::ENV] = $_ENV[ScheduleOffset::ENV] = $minutes;
        putenv(ScheduleOffset::ENV.'='.$minutes);
        $this->refreshApplication();
    }

    /** @return array<string, string> */
    private function expressions(): array
    {
        $expressions = [];
        foreach ($this->app->make(Schedule::class)->events() as $event) {
            /** @var Event $event */
            preg_match("/artisan'? (\S+)/", $event->command, $name);
            $expressions[$name[1]] = $event->expression;
        }

        return $expressions;
    }
}
