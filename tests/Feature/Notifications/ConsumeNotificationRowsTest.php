<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Features\Notifications\ConsumeNotificationRows;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConsumeNotificationRowsTest extends TestCase
{
    use RefreshDatabase;

    /** Bites only on SQLite before 3.32, where 1,000 bound uuids pass its 999 bound parameters. */
    public function test_marks_more_rows_read_than_one_update_could_bind(): void
    {
        $member = Member::factory()->create();
        $ids = array_map(fn (): string => (string) Str::uuid(), range(1, 1000));
        foreach (array_chunk($ids, 100) as $part) {
            DB::table('notifications')->insert(array_map(fn (string $id): array => [
                'id' => $id,
                'type' => 'test',
                'notifiable_type' => $member->getMorphClass(),
                'notifiable_id' => $member->getKey(),
                'data' => '{}',
                'created_at' => now(),
                'updated_at' => now(),
            ], $part));
        }

        app(ConsumeNotificationRows::class)->markRead(...$ids);

        $this->assertSame(0, DatabaseNotification::query()->whereNull('read_at')->count());
    }
}
