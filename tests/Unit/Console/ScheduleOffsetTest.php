<?php

declare(strict_types=1);

namespace Tests\Unit\Console;

use App\Console\ScheduleOffset;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ScheduleOffsetTest extends TestCase
{
    public function test_no_offset_is_the_time_itself(): void
    {
        $this->assertSame('0 6 * * *', ScheduleOffset::daily('06:00', 0));
        $this->assertSame('0 0 * * *', ScheduleOffset::daily('00:00', 0));
        $this->assertSame('10 3 * * 0', ScheduleOffset::weekly(0, '03:10', 0));
    }

    public function test_an_offset_carries_into_the_next_hour_and_day(): void
    {
        $this->assertSame('35 7 * * *', ScheduleOffset::daily('06:00', 95));
        $this->assertSame('55 5 * * *', ScheduleOffset::daily('06:00', ScheduleOffset::MAX_MINUTES));
        $this->assertSame('5 0 * * *', ScheduleOffset::daily('23:55', 10));
    }

    public function test_a_weekly_task_carried_past_midnight_moves_to_the_next_weekday(): void
    {
        $this->assertSame('5 3 * * 1', ScheduleOffset::weekly(0, '03:10', ScheduleOffset::MAX_MINUTES));
        $this->assertSame('0 0 * * 0', ScheduleOffset::weekly(6, '23:55', 5));
    }

    /** @return iterable<string, array{mixed, int}> */
    public static function accepted(): iterable
    {
        yield 'unset' => [null, 0];
        yield 'empty' => ['', 0];
        yield 'zero' => ['0', 0];
        yield 'int' => [30, 30];
        yield 'string' => ['30', 30];
        yield 'largest' => ['1435', 1435];
    }

    #[DataProvider('accepted')]
    public function test_accepted_values(mixed $value, int $minutes): void
    {
        $this->assertSame($minutes, ScheduleOffset::minutes($value));
    }

    /** @return iterable<string, array{mixed}> */
    public static function refused(): iterable
    {
        yield 'not a multiple of five' => ['7'];
        yield 'negative' => ['-5'];
        yield 'a day' => ['1440'];
        yield 'word' => ['abc'];
        yield 'exponent' => ['1e2'];
        yield 'decimal' => ['5.0'];
        yield 'padded' => [' 5'];
        yield 'bool from env' => [true];
        yield 'negative int' => [-5];
    }

    #[DataProvider('refused')]
    public function test_refused_values_name_the_env(mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(ScheduleOffset::ENV);

        ScheduleOffset::minutes($value);
    }
}
