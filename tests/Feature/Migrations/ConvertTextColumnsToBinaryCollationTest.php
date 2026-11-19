<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use App\Models\AdminUser;
use App\Models\Diary;
use App\Models\DiaryComment;
use App\Models\File;
use App\Models\Group;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Support\Database\IdentifierCollation;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * DatabaseMigrations rather than RefreshDatabase: SQLite ignores the foreign-key pragma inside the
 * test's transaction, so the rebuild would cascade, and MySQL's DDL would commit it.
 */
class ConvertTextColumnsToBinaryCollationTest extends TestCase
{
    use DatabaseMigrations;

    private const IDENTIFIERS = [
        'members' => 'email',
        'registration_tokens' => 'email',
        'password_reset_tokens' => 'email',
        'email_change_requests' => 'new_email',
        'admin_users' => 'username',
    ];

    /** Two shapes of table an upgraded site keeps beside OpenPNE 4's: utf8mb3, and utf8mb4 with a column comment. */
    private const OP3_TABLES = [
        'member_config' => "CREATE TABLE member_config (id INT PRIMARY KEY, name VARCHAR(64) NOT NULL DEFAULT '', value TEXT) DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci",
        'op3_profile' => "CREATE TABLE op3_profile (id INT PRIMARY KEY, name VARCHAR(64) COMMENT 'Profile name') DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];

    public function test_the_sqlite_rebuild_keeps_child_rows_and_the_members_triggers(): void
    {
        $this->onlyOn('sqlite');
        $owner = Member::factory()->create();
        $diary = Diary::factory()->create(['member_id' => $owner->getKey()]);
        DiaryComment::factory()->count(2)->create(['diary_id' => $diary->getKey()]);
        $ai = Member::factory()->aiAccount($owner)->create();
        $counts = $this->counts(['members', 'diaries', 'diary_comments']);
        $migration = $this->migration();

        $migration->down();
        $this->assertIdentifierCollations(null);
        $this->assertAnAiAccountStillRefusesAnEmail($owner, $ai);

        $migration->up();
        $this->assertIdentifierCollations('nocase');
        $this->assertSame($counts, $this->counts(['members', 'diaries', 'diary_comments']));
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
        $this->assertSame(1, (int) DB::scalar('PRAGMA foreign_keys'));
        $this->assertAnAiAccountStillRefusesAnEmail($owner, $ai);
    }

    public function test_a_failure_during_the_sqlite_rebuild_leaves_every_table_as_it_was(): void
    {
        $this->onlyOn('sqlite');
        $owner = Member::factory()->create();
        Diary::factory()->create(['member_id' => $owner->getKey()]);
        $migration = $this->migration();
        $migration->down();
        $schema = $this->sqliteSchema();
        $counts = $this->counts(['members', 'diaries']);

        $armed = true;
        DB::listen(function (QueryExecuted $query) use (&$armed) {
            if ($armed && $query->sql === 'drop table "members"') {
                $armed = false;
                throw new RuntimeException('injected');
            }
        });

        try {
            $migration->up();
            $this->fail('the injected failure did not stop the rebuild');
        } catch (RuntimeException $e) {
            $this->assertSame('injected', $e->getMessage());
        }

        $this->assertSame($schema, $this->sqliteSchema());
        $this->assertSame($counts, $this->counts(['members', 'diaries']));
        $this->assertSame(1, (int) DB::scalar('PRAGMA foreign_keys'));
    }

    public function test_values_the_identifier_collation_would_merge_stop_it_before_anything_changes(): void
    {
        $migration = $this->migration();
        $migration->down();
        $one = Member::factory()->create(['email' => 'taro@example.com']);
        $other = Member::factory()->create(['email' => 'TARO@example.com']);
        $admin = AdminUser::factory()->create(['username' => 'root']);
        $otherAdmin = AdminUser::factory()->create(['username' => 'Root']);
        DB::table('password_reset_tokens')->insert([
            ['email' => 'jiro@example.com', 'token' => 'a', 'created_at' => now()],
            ['email' => 'Jiro@example.com', 'token' => 'b', 'created_at' => now()],
        ]);
        $this->requestEmailChange('pending@example.com', 'PENDING@example.com');

        $message = $this->refusal($migration);

        $this->assertStringContainsString("members.email holds values the new comparison treats as one (ids {$one->getKey()}, {$other->getKey()})", $message);
        $this->assertStringContainsString("admin_users.username holds values the new comparison treats as one (ids {$admin->getKey()}, {$otherAdmin->getKey()})", $message);
        $this->assertStringContainsString('password_reset_tokens.email holds values the new comparison treats as one (2 rows;', $message);
        $this->assertStringNotContainsString('email_change_requests', $message);
        $this->assertStringNotContainsString('example.com', $message);
        $this->assertStringNotContainsString('root', strtolower(str_replace('admin_users', '', $message)));
        $this->assertIdentifierCollations($this->binary());
    }

    public function test_values_with_whitespace_at_either_end_stop_it(): void
    {
        $migration = $this->migration();
        $migration->down();
        $member = Member::factory()->create(['email' => 'hanako@example.com ']);
        $admin = AdminUser::factory()->create(['username' => "\troot"]);
        DB::table('password_reset_tokens')->insert(['email' => ' saburo@example.com', 'token' => 'a', 'created_at' => now()]);
        Member::factory()->create(['email' => 'plain@example.com']);

        $message = $this->refusal($migration);

        $this->assertStringContainsString("members.email holds values with whitespace at either end, which no one can type (ids {$member->getKey()})", $message);
        $this->assertStringContainsString("admin_users.username holds values with whitespace at either end, which no one can type (ids {$admin->getKey()})", $message);
        $this->assertStringContainsString('password_reset_tokens.email holds values with whitespace at either end, which no one can type (1 rows;', $message);
        $this->assertStringNotContainsString('example.com', $message);
        $this->assertIdentifierCollations($this->binary());
    }

    public function test_two_members_asking_for_the_same_address_do_not_stop_it(): void
    {
        $migration = $this->migration();
        $migration->down();
        $this->requestEmailChange('pending@example.com', 'PENDING@example.com');

        $migration->up();

        $this->assertIdentifierCollations(strtolower((string) IdentifierCollation::for(DB::connection())));
        $this->assertSame(2, DB::table('email_change_requests')->where('new_email', 'pending@example.com')->count());
    }

    public function test_a_mysql_site_made_under_unicode_ci_converts_its_own_tables_and_nothing_else(): void
    {
        $this->onlyOn('mysql');
        $this->useCollation('utf8mb4_unicode_ci');
        $this->artisan('migrate:fresh');

        try {
            foreach (self::OP3_TABLES as $table => $create) {
                DB::statement($create);
                DB::table($table)->insert(['id' => 1, 'name' => 'Ｔａｒｏ ']);
            }
            DB::table('file_bin')->insert(['file_id' => File::factory()->create()->getKey(), 'bin' => 'bytes', 'created_at' => now(), 'updated_at' => now()]);
            $this->seedOneOfEach();

            $tables = $this->migration()::TABLES;
            $this->assertSame(['utf8mb4_unicode_ci'], collect(Schema::getTables(DB::connection()->getDatabaseName()))
                ->whereIn('name', $tables)->pluck('collation')->unique()->values()->all(), 'migrate left the tables in the collation the site chose');
            $definitions = $this->mysqlDefinitions($tables);
            $indexes = array_map(fn (string $table) => [Schema::getIndexes($table), Schema::getForeignKeys($table)], $tables);
            $rows = $this->rows($tables);
            $untouched = $this->untouched();

            $this->assertSame(0, $this->altersRunBy(fn () => $this->migration()->up()), 'a site that chose another collation is not converted');

            $this->useCollation('utf8mb4_0900_bin');
            $this->migration()->up();

            $this->assertSame($definitions, $this->mysqlDefinitions($tables));
            $this->assertSame($indexes, array_map(fn (string $table) => [Schema::getIndexes($table), Schema::getForeignKeys($table)], $tables));
            $this->assertSame($rows, $this->rows($tables));
            $this->assertSame($untouched, $this->untouched());
            $this->assertSame(
                ['admin_users.username' => 'utf8mb4_0900_as_ci', 'email_change_requests.new_email' => 'utf8mb4_0900_as_ci',
                    'members.email' => 'utf8mb4_0900_as_ci', 'password_reset_tokens.email' => 'utf8mb4_0900_as_ci',
                    'push_subscriptions.endpoint' => 'ascii_bin', 'registration_tokens.email' => 'utf8mb4_0900_as_ci'],
                $this->mysqlColumnsNotIn($tables, 'utf8mb4_0900_bin'),
            );
            $this->assertSame([], collect(DB::select(
                'SELECT TABLE_NAME, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_COLLATION <> ?',
                ['utf8mb4_0900_bin'],
            ))->pluck('TABLE_COLLATION', 'TABLE_NAME')->except(['file_bin', ...array_keys(self::OP3_TABLES)])->all());

            $this->assertSame(0, $this->altersRunBy(fn () => $this->migration()->up()), 'a second run converts nothing');
        } finally {
            foreach (array_keys(self::OP3_TABLES) as $table) {
                DB::statement("DROP TABLE IF EXISTS {$table}");
            }
            $this->useCollation('utf8mb4_0900_bin');
        }
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_11_19_000001_convert_text_columns_to_binary_collation.php');
    }

    private function onlyOn(string $driver): void
    {
        if (DB::connection()->getDriverName() !== $driver) {
            $this->markTestSkipped("{$driver} only");
        }
    }

    private function binary(): ?string
    {
        return DB::connection()->getDriverName() === 'mysql' ? 'utf8mb4_0900_bin' : null;
    }

    private function useCollation(string $collation): void
    {
        config(['database.connections.mysql.collation' => $collation]);
        DB::purge('mysql');
    }

    private function refusal(Migration $migration): string
    {
        try {
            $migration->up();
        } catch (RuntimeException $e) {
            $this->assertStringStartsWith('Nothing was converted.', $e->getMessage());

            return $e->getMessage();
        }

        $this->fail('the migration converted values it should have refused');
    }

    private function assertIdentifierCollations(?string $expected): void
    {
        foreach (self::IDENTIFIERS as $table => $column) {
            $collation = collect(Schema::getColumns($table))->firstWhere('name', $column)['collation'];
            $this->assertSame($expected, $collation === null ? null : strtolower($collation), "{$table}.{$column}");
        }
    }

    private function assertAnAiAccountStillRefusesAnEmail(Member $owner, Member $ai): void
    {
        foreach ([
            fn () => DB::table('members')->insert(['name' => 'Impostor', 'email' => 'ai@example.com', 'owner_member_id' => $owner->getKey()]),
            fn () => DB::table('members')->where('id', $ai->getKey())->update(['email' => 'ai@example.com']),
        ] as $write) {
            try {
                $write();
                $this->fail('an AI account took an email');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function requestEmailChange(string ...$addresses): void
    {
        foreach ($addresses as $i => $address) {
            DB::table('email_change_requests')->insert([
                'member_id' => Member::factory()->create()->getKey(), 'new_email' => $address,
                'token' => "token-{$i}", 'created_at' => now(),
            ]);
        }
    }

    /**
     * @param  list<string>  $tables
     * @return array<string, int>
     */
    private function counts(array $tables): array
    {
        return array_combine($tables, array_map(fn (string $table) => DB::table($table)->count(), $tables));
    }

    /** @return array<string, string> */
    private function sqliteSchema(): array
    {
        return DB::table('sqlite_master')->whereIn('tbl_name', array_keys(self::IDENTIFIERS))
            ->orWhere('name', 'like', '__temp__%')->orderBy('name')->pluck('sql', 'name')->all();
    }

    private function seedOneOfEach(): void
    {
        $member = Member::factory()->create(['email' => 'Taro@Example.com']);
        Group::factory()->create(['name' => 'Ｔｅｎｎｉｓ部']);
        Diary::factory()->create(['member_id' => $member->getKey(), 'title' => 'はなの会']);
        $post = TimelinePost::factory()->create(['member_id' => $member->getKey()]);
        DB::table('timeline_post_tags')->insert(['timeline_post_id' => $post->getKey(), 'tag' => 'café', 'offset' => 0, 'length' => 5]);
        DB::table('reactions')->insert(['reactable_type' => 'timeline_post', 'reactable_id' => $post->getKey(), 'member_id' => $member->getKey(), 'emoji' => "\u{2764}\u{FE0F}", 'created_at' => now()]);
        AdminUser::factory()->create(['username' => 'Admin']);
    }

    /**
     * @param  list<string>  $tables
     * @return list<array<string, mixed>>
     */
    private function mysqlDefinitions(array $tables): array
    {
        return array_map(fn (object $row) => (array) $row, DB::select(
            'SELECT TABLE_NAME, COLUMN_NAME, ORDINAL_POSITION, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, CHARACTER_SET_NAME
             FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('.implode(', ', array_fill(0, count($tables), '?')).')
             ORDER BY TABLE_NAME, ORDINAL_POSITION',
            $tables,
        ));
    }

    /**
     * @param  list<string>  $tables
     * @return array<string, string>
     */
    private function mysqlColumnsNotIn(array $tables, string $collation): array
    {
        return collect(DB::select(
            'SELECT CONCAT(TABLE_NAME, ".", COLUMN_NAME) AS name, COLLATION_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND COLLATION_NAME IS NOT NULL AND COLLATION_NAME <> ? AND TABLE_NAME IN ('.implode(', ', array_fill(0, count($tables), '?')).')
             ORDER BY name',
            [$collation, ...$tables],
        ))->pluck('COLLATION_NAME', 'name')->all();
    }

    /**
     * @param  list<string>  $tables
     * @return array<string, list<string>>
     */
    private function rows(array $tables): array
    {
        return array_combine($tables, array_map(function (string $table) {
            $rows = DB::table($table)->get()->map(fn (object $row) => json_encode($row, JSON_THROW_ON_ERROR))->all();
            sort($rows);

            return $rows;
        }, $tables));
    }

    /** @return array<string, array{string, string}> */
    private function untouched(): array
    {
        $tables = ['file_bin', ...array_keys(self::OP3_TABLES)];

        return array_combine($tables, array_map(fn (string $table) => [
            DB::selectOne("SHOW CREATE TABLE {$table}")->{'Create Table'},
            json_encode(DB::table($table)->get(), JSON_THROW_ON_ERROR),
        ], $tables));
    }

    private function altersRunBy(callable $run): int
    {
        $alters = 0;
        DB::listen(function (QueryExecuted $query) use (&$alters) {
            $alters += str_starts_with($query->sql, 'ALTER TABLE') ? 1 : 0;
        });
        $run();

        return $alters;
    }
}
