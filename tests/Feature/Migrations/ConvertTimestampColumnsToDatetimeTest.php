<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use App\Models\Diary;
use App\Models\GroupMember;
use App\Models\HomeIssue;
use App\Models\Member;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * DatabaseMigrations rather than RefreshDatabase: the migration's DDL commits on MySQL, and the next
 * test needs the stock schema back.
 */
class ConvertTimestampColumnsToDatetimeTest extends TestCase
{
    use DatabaseMigrations;

    /** Puts back each shape an installed site's columns have; friendships carries what explicit_defaults_for_timestamp=OFF adds. */
    private const AS_INSTALLED = [
        'diaries' => 'MODIFY created_at TIMESTAMP NULL, MODIFY updated_at TIMESTAMP NULL',
        'friendships' => 'MODIFY created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
        'home_issues' => 'MODIFY window_start TIMESTAMP NOT NULL, MODIFY published_at TIMESTAMP NOT NULL',
        'group_members' => 'MODIFY talk_read_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
    ];

    private const WRITTEN = '2030-01-02 03:04:05';

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Only MySQL has TIMESTAMP columns to convert.');
        }
    }

    public function test_up_keeps_the_values_the_application_read_and_gives_the_columns_a_fresh_install_definition(): void
    {
        $fresh = $this->definitions();
        $freshIndexes = $this->indexes();
        [$one, $other] = Member::factory()->count(2)->create()->all();
        Diary::factory()->create();
        HomeIssue::factory()->create();
        GroupMember::factory()->create();
        DB::table('friendships')->insert(['member_id' => $one->getKey(), 'friend_id' => $other->getKey(), 'created_at' => now()]);

        foreach (self::AS_INSTALLED as $table => $modify) {
            DB::statement("ALTER TABLE {$table} {$modify}");
        }
        DB::statement('CREATE TABLE op3_like (id INT PRIMARY KEY, created_at TIMESTAMP NULL)');

        try {
            // A session time_zone other than the server's UTC, as on a site whose MySQL runs in local time.
            DB::statement("SET time_zone = '+09:00'");
            DB::table('diaries')->update(['created_at' => self::WRITTEN, 'updated_at' => null]);
            DB::table('friendships')->update(['created_at' => self::WRITTEN]);
            DB::table('home_issues')->update(['window_start' => self::WRITTEN, 'published_at' => self::WRITTEN]);
            DB::table('group_members')->update(['talk_read_at' => self::WRITTEN]);

            $this->migration()->up();

            $this->assertWritten();
            DB::statement("SET time_zone = '+00:00'");
            $this->assertWritten();

            $this->assertSame($fresh, $this->definitions());
            $this->assertSame($freshIndexes, $this->indexes());
            $this->assertSame('timestamp', $this->typeOf('op3_like', 'created_at'));
            $this->assertRefused(fn () => DB::table('friendships')->insert(['member_id' => $other->getKey(), 'friend_id' => $one->getKey()]));

            $this->migration()->up();
            $this->assertSame($fresh, $this->definitions());
            $this->assertWritten();
        } finally {
            DB::statement('DROP TABLE IF EXISTS op3_like');
        }
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_11_18_000001_convert_timestamp_columns_to_datetime.php');
    }

    private function assertWritten(): void
    {
        $this->assertSame(self::WRITTEN, DB::table('diaries')->value('created_at'));
        $this->assertNull(DB::table('diaries')->value('updated_at'));
        $this->assertSame(self::WRITTEN, DB::table('friendships')->value('created_at'));
        $this->assertSame([self::WRITTEN, self::WRITTEN], array_values((array) DB::table('home_issues')->first(['window_start', 'published_at'])));
        $this->assertSame(self::WRITTEN, DB::table('group_members')->value('talk_read_at'));
    }

    /** @return list<array<string, mixed>> */
    private function definitions(): array
    {
        return array_map(fn (object $row) => (array) $row, DB::select(
            'SELECT TABLE_NAME, COLUMN_NAME, ORDINAL_POSITION, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (?, ?, ?, ?)
             ORDER BY TABLE_NAME, ORDINAL_POSITION',
            array_keys(self::AS_INSTALLED),
        ));
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function indexes(): array
    {
        return array_combine(
            array_keys(self::AS_INSTALLED),
            array_map(fn (string $table) => Schema::getIndexes($table), array_keys(self::AS_INSTALLED)),
        );
    }

    private function typeOf(string $table, string $column): string
    {
        return collect(Schema::getColumns($table))->firstWhere('name', $column)['type_name'];
    }

    private function assertRefused(callable $write): void
    {
        try {
            $write();
        } catch (QueryException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail('the database accepted the write');
    }
}
