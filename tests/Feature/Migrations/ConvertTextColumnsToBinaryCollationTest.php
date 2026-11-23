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

    private const MIGRATION = '2026_11_19_000001_convert_text_columns_to_binary_collation.php';

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

    public function test_the_sqlite_rebuild_keeps_the_ids_already_handed_out(): void
    {
        $this->onlyOn('sqlite');
        Member::factory()->create();
        DB::table('members')->where('id', Member::factory()->create()->getKey())->delete();
        DB::table('admin_users')->where('id', AdminUser::factory()->create()->getKey())->delete();
        $sequences = $this->sequences();
        $this->assertGreaterThan(DB::table('members')->max('id'), $sequences['members']);
        $this->assertTrue(DB::table('admin_users')->doesntExist() && $sequences['admin_users'] > 0);
        $migration = $this->migration();

        $migration->down();
        $this->assertSame($sequences, $this->sequences());

        $migration->up();
        $this->assertSame($sequences, $this->sequences());
    }

    public function test_the_sqlite_rebuild_leaves_foreign_keys_off_when_it_found_them_off(): void
    {
        $this->onlyOn('sqlite');
        $migration = $this->migration();
        DB::statement('PRAGMA foreign_keys = OFF');

        try {
            $migration->down();
            $this->assertSame(0, (int) DB::scalar('PRAGMA foreign_keys'));

            $migration->up();
            $this->assertSame(0, (int) DB::scalar('PRAGMA foreign_keys'));
        } finally {
            DB::statement('PRAGMA foreign_keys = ON');
        }
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
        $invited = DB::table('registration_tokens')->insertGetId(['email' => 'shiro@example.com', 'token' => 'c']);
        $reinvited = DB::table('registration_tokens')->insertGetId(['email' => 'Shiro@example.com', 'token' => 'd']);
        $this->requestEmailChange('pending@example.com', 'PENDING@example.com');

        $message = $this->refusal($migration);

        $this->assertStringContainsString("members.email holds values the new comparison treats as one (ids {$one->getKey()}, {$other->getKey()})", $message);
        $this->assertStringContainsString("admin_users.username holds values the new comparison treats as one (ids {$admin->getKey()}, {$otherAdmin->getKey()})", $message);
        $this->assertStringContainsString('password_reset_tokens.email holds values the new comparison treats as one (2 rows;', $message);
        $this->assertStringContainsString("registration_tokens.email holds values the new comparison treats as one (ids {$invited}, {$reinvited})", $message);
        $this->assertStringNotContainsString('email_change_requests', $message);
        $this->assertStringNotContainsString('example.com', $message);
        $this->assertStringNotContainsString('root', strtolower(str_replace('admin_users', '', $message)));
        $this->assertIdentifierCollations($this->binary());
    }

    public function test_values_with_whitespace_at_either_end_stop_it(): void
    {
        $migration = $this->migration();
        $migration->down();
        $member = Member::factory()->create();
        DB::table('members')->where('id', $member->getKey())->update(['email' => 'hanako@example.com ']); // as an upgrade carries it
        $admin = AdminUser::factory()->create(['username' => "\troot"]);
        DB::table('password_reset_tokens')->insert(['email' => ' saburo@example.com', 'token' => 'a', 'created_at' => now()]);
        $invited = DB::table('registration_tokens')->insertGetId(['email' => "goro@example.com\u{3000}", 'token' => 'b']);
        Member::factory()->create(['email' => 'plain@example.com']);

        $message = $this->refusal($migration);

        $this->assertStringContainsString("members.email holds values with whitespace at either end, which no one can type (ids {$member->getKey()})", $message);
        $this->assertStringContainsString("admin_users.username holds values with whitespace at either end, which no one can type (ids {$admin->getKey()})", $message);
        $this->assertStringContainsString('password_reset_tokens.email holds values with whitespace at either end, which no one can type (1 rows;', $message);
        $this->assertStringContainsString("registration_tokens.email holds values with whitespace at either end, which no one can type (ids {$invited})", $message);
        $this->assertStringNotContainsString('example.com', $message);
        $this->assertIdentifierCollations($this->binary());
    }

    public function test_pending_changes_to_one_address_and_accounts_without_an_email_do_not_stop_it(): void
    {
        $migration = $this->migration();
        $migration->down();
        $this->requestEmailChange('pending@example.com', 'PENDING@example.com');
        Member::factory()->count(2)->aiAccount(Member::factory()->create())->create();

        $migration->up();

        $this->assertIdentifierCollations(strtolower((string) IdentifierCollation::for(DB::connection())));
        $this->assertSame(2, DB::table('email_change_requests')->where('new_email', 'pending@example.com')->count());
    }

    public function test_a_mysql_site_made_under_unicode_ci_converts_its_own_tables_and_nothing_else(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('mysql and mariadb only');
        }
        $binary = (string) $this->binary();
        $identifier = (string) IdentifierCollation::for(DB::connection());
        $this->useCollation('utf8mb4_unicode_ci');
        $this->artisan('migrate:fresh', ['--realpath' => true, '--path' => array_values(array_filter(
            glob(database_path('migrations/*.php')),
            fn (string $file) => basename($file) <= self::MIGRATION,
        ))]);

        try {
            foreach (self::OP3_TABLES as $table => $create) {
                DB::statement($create);
                DB::table($table)->insert(['id' => 1, 'name' => 'Ｔａｒｏ ']);
            }
            DB::table('file_bin')->insert(['file_id' => File::factory()->create()->getKey(), 'bin' => 'bytes', 'created_at' => now(), 'updated_at' => now()]);
            $this->seedOneOfEach();
            // MariaDB reports these defaults as SQL literals that the conversion writes back as they are; on MySQL an expression default is DEFAULT_GENERATED, an attribute the conversion refuses.
            DB::statement("ALTER TABLE profiles ADD COLUMN quoted VARCHAR(16) NOT NULL DEFAULT 'it''s \\\\ x', ADD COLUMN null_word VARCHAR(8) NULL DEFAULT 'NULL', ADD COLUMN sql_null VARCHAR(8) NULL DEFAULT NULL");
            if (DB::connection()->getDriverName() === 'mariadb') {
                DB::statement('ALTER TABLE profiles ADD COLUMN expression VARCHAR(36) NULL DEFAULT (uuid())');
            }

            $tables = $this->migration()::TABLES;
            $this->assertSame(['utf8mb4_unicode_ci'], collect(Schema::getTables(DB::connection()->getDatabaseName()))
                ->whereIn('name', $tables)->pluck('collation')->unique()->values()->all(), 'migrate left the tables in the collation the site chose');
            $definitions = $this->mysqlDefinitions($tables);
            $checks = $this->checks();
            $indexes = array_map(fn (string $table) => [Schema::getIndexes($table), Schema::getForeignKeys($table)], $tables);
            $rows = $this->rows($tables);
            $untouched = $this->untouched();

            $this->assertSame(0, $this->altersRunBy(fn () => $this->migration()->up()), 'a site that chose another collation is not converted');

            $this->useCollation($binary);
            $this->migration()->up();

            $this->assertSame($definitions, $this->mysqlDefinitions($tables));
            $this->assertSame($checks, $this->checks());
            $this->assertSame($indexes, array_map(fn (string $table) => [Schema::getIndexes($table), Schema::getForeignKeys($table)], $tables));
            $this->assertSame($rows, $this->rows($tables));
            $this->assertSame($untouched, $this->untouched());
            $json = DB::connection()->getDriverName() === 'mariadb'
                ? ['home_issue_items.stats' => 'utf8mb4_bin', 'openpne4_upgrade_state.metadata' => 'utf8mb4_bin', 'passkeys.credential' => 'utf8mb4_bin']
                : [];
            $expected = ['admin_users.username' => $identifier, 'email_change_requests.new_email' => $identifier,
                'members.email' => $identifier, 'password_reset_tokens.email' => $identifier,
                'push_subscriptions.endpoint' => 'ascii_bin', 'registration_tokens.email' => $identifier, ...$json];
            ksort($expected);
            $this->assertSame($expected, $this->mysqlColumnsNotIn($tables, $binary));
            $this->assertSame(0, $this->altersRunBy(fn () => $this->migration()->up()), 'a second run converts nothing');

            $this->artisan('migrate');
            $this->assertSame([], collect(DB::select(
                'SELECT TABLE_NAME, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_COLLATION <> ?',
                [$binary],
            ))->pluck('TABLE_COLLATION', 'TABLE_NAME')->except(['file_bin', ...array_keys(self::OP3_TABLES)])->all());
        } finally {
            foreach (array_keys(self::OP3_TABLES) as $table) {
                DB::statement("DROP TABLE IF EXISTS {$table}");
            }
            $this->useCollation($binary);
        }
    }

    public function test_a_column_check_other_than_mariadbs_json_stops_it_even_on_a_column_already_converted(): void
    {
        $this->onlyOn('mariadb');
        $binary = (string) $this->binary();
        $this->useCollation('utf8mb4_unicode_ci');
        $this->artisan('migrate:fresh', ['--realpath' => true, '--path' => array_values(array_filter(
            glob(database_path('migrations/*.php')),
            fn (string $file) => basename($file) <= self::MIGRATION,
        ))]);

        try {
            DB::statement("ALTER TABLE profiles ADD COLUMN probe VARCHAR(8) COLLATE {$binary} NULL CHECK (probe <> '')");
            $this->useCollation($binary);
            $definitions = $this->mysqlDefinitions(['profiles']);
            $stopped = null;

            try {
                $this->migration()->up();
            } catch (RuntimeException $e) {
                $stopped = $e;
            }

            $this->assertSame('Nothing was converted: profiles.probe has a check this migration does not copy.', $stopped?->getMessage());
            $this->assertSame($definitions, $this->mysqlDefinitions(['profiles']));
        } finally {
            DB::statement('ALTER TABLE profiles DROP COLUMN IF EXISTS probe');
            $this->useCollation($binary);
        }
    }

    /** The other driver borrows this session, since its collation would refuse to connect; without the refusal the conversion reads one engine's information_schema as the other's. */
    public function test_a_connection_of_the_other_engines_driver_stops_it(): void
    {
        $driver = DB::connection()->getDriverName();

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('mysql and mariadb only');
        }

        [$other, $collation, $server] = $driver === 'mariadb' ? ['mysql', 'utf8mb4_0900_bin', 'MariaDB'] : ['mariadb', 'utf8mb4_nopad_bin', 'MySQL'];
        config(['database.connections.other_engine' => [...DB::connection()->getConfig(), 'name' => 'other_engine', 'driver' => $other, 'collation' => $collation]]);
        DB::connection('other_engine')->setPdo(DB::connection()->getPdo());
        $default = config('database.default');
        $tables = $this->migration()::TABLES;
        $definitions = $this->mysqlDefinitions($tables);
        $checks = $this->checks();
        $stopped = null;

        try {
            config(['database.default' => 'other_engine']);
            $this->migration()->up();
        } catch (RuntimeException $e) {
            $stopped = $e;
        } finally {
            config(['database.default' => $default]);
            DB::purge('other_engine');
        }

        $this->assertSame("Nothing was converted: the {$other} connection reaches a {$server} server, which takes the {$driver} one.", $stopped?->getMessage());
        $this->assertSame($definitions, $this->mysqlDefinitions($tables));
        $this->assertSame($checks, $this->checks());
    }

    private function migration(): Migration
    {
        return require database_path('migrations/'.self::MIGRATION);
    }

    private function onlyOn(string $driver): void
    {
        if (DB::connection()->getDriverName() !== $driver) {
            $this->markTestSkipped("{$driver} only");
        }
    }

    private function binary(): ?string
    {
        return match (DB::connection()->getDriverName()) {
            'mysql' => 'utf8mb4_0900_bin',
            'mariadb' => 'utf8mb4_nopad_bin',
            default => null,
        };
    }

    private function useCollation(string $collation): void
    {
        $connection = DB::getDefaultConnection();
        config(["database.connections.{$connection}.collation" => $collation]);
        DB::purge($connection);
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

    /** @return array<string, int> */
    private function sequences(): array
    {
        return DB::table('sqlite_sequence')->whereIn('name', array_keys(self::IDENTIFIERS))->orderBy('name')
            ->pluck('seq', 'name')->map(fn ($seq) => (int) $seq)->all();
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

    /** @return array<string, string> */
    private function checks(): array
    {
        // MariaDB names a column check after its column, so two tables may share a name; MySQL's view has no TABLE_NAME.
        $name = DB::connection()->getDriverName() === 'mariadb' ? "CONCAT(TABLE_NAME, '.', CONSTRAINT_NAME)" : 'CONSTRAINT_NAME';

        return collect(DB::select("SELECT {$name} AS name, CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() ORDER BY name"))
            ->pluck('CHECK_CLAUSE', 'name')->all();
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
