<?php

declare(strict_types=1);

namespace Tests\Unit\Console;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

final class ScheduleTimesTest extends TestCase
{
    public function test_every_task_is_due_at_a_minute_that_cron_every_five_minutes_reaches(): void
    {
        $events = $this->app->make(Schedule::class)->events();
        $this->assertNotEmpty($events);

        foreach ($events as $event) {
            $this->assertTrue(self::everyFiveMinutesReaches($event), "{$event->expression} ({$event->command}) is due at a minute that cron every five minutes misses (docs/internals/runtime.md, \"Scheduled tasks\")");
        }
    }

    private static function everyFiveMinutesReaches(Event $event): bool
    {
        if ($event->isRepeatable()) {
            return false;
        }
        $minute = explode(' ', $event->expression)[0];
        if (preg_match('/\A\*\/(\d+)\z/', $minute, $step) === 1) {
            return (int) $step[1] % 5 === 0;
        }
        if (preg_match('/\A\d+(,\d+)*\z/', $minute) !== 1) {
            return false;
        }

        return array_filter(explode(',', $minute), fn (string $at): bool => (int) $at % 5 !== 0) === [];
    }
}
