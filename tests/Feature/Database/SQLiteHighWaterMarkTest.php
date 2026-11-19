<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Throwable;

/**
 * Each case runs a throwaway migration through the real migrator on its own SQLite connection, so it
 * holds on both CI lanes; `probe` has numbered up to 100 and kept only id 1.
 */
class SQLiteHighWaterMarkTest extends TestCase
{
    private const CHANGE = "Schema::table('probe', fn (Blueprint \$t) => \$t->string('label')->nullable()->change());";

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.rowids' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);
        DB::purge('rowids');

        Schema::connection('rowids')->create('parents', fn (Blueprint $table) => $table->id());
        Schema::connection('rowids')->create('probe', function (Blueprint $table): void {
            $table->id();
            $table->string('label');
        });
        $this->probe()->insert([['id' => 1, 'label' => 'kept'], ['id' => 2, 'label' => 'gone'], ['id' => 100, 'label' => 'gone']]);
        $this->probe()->where('id', '>', 1)->delete();

        $this->dir = sys_get_temp_dir().'/openpne-rowids-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($this->dir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        DB::purge('rowids');

        parent::tearDown();
    }

    /** @return array<string, array{string, string, ?int, int}> up() body, table read after, id of the row up() inserts, next id */
    public static function rebuilds(): array
    {
        $inside = " DB::table('probe')->insert(['label' => 'inside']);";

        return [
            'change()' => [self::CHANGE, 'probe', null, 101],
            'an insert after the rebuild in the same up()' => [self::CHANGE.$inside, 'probe', 101, 102],
            'an exception after the rebuild' => [self::CHANGE." throw new \\RuntimeException('after the rebuild');", 'probe', null, 101],
            'a foreign key added' => ["Schema::table('probe', fn (Blueprint \$t) => \$t->foreignId('parent_id')->nullable()->constrained('parents'));".$inside, 'probe', 101, 102],
            'a rename, then a rebuild' => ["Schema::rename('probe', 'renamed'); Schema::table('renamed', fn (Blueprint \$t) => \$t->string('label')->nullable()->change()); DB::table('renamed')->insert(['label' => 'inside']);", 'renamed', 101, 102],
            'a rebuild and a rename in one Blueprint' => ["Schema::table('probe', function (Blueprint \$t) { \$t->string('label')->nullable()->change(); \$t->rename('renamed'); }); DB::table('renamed')->insert(['label' => 'inside']);", 'renamed', 101, 102],
            'a higher explicit id after the rebuild' => [self::CHANGE." DB::table('probe')->insert(['id' => 200, 'label' => 'inside']);", 'probe', 200, 201],
        ];
    }

    #[DataProvider('rebuilds')]
    public function test_a_rebuild_does_not_hand_out_a_deleted_id(string $up, string $table, ?int $insideId, int $nextId): void
    {
        try {
            $this->migrate($up);
        } catch (Throwable $e) {
            $this->assertSame('after the rebuild', $e->getMessage());
        }

        $this->assertSame($insideId, $this->db()->table($table)->where('label', 'inside')->value('id'));
        $this->assertSame($nextId, (int) $this->db()->table($table)->insertGetId(['label' => 'after']));
    }

    public function test_a_rebuild_of_an_emptied_table_keeps_its_mark(): void
    {
        // The rebuild copies no row, so the copy's mark starts at 0 rather than at a surviving id.
        $this->probe()->delete();

        $this->migrate(self::CHANGE);

        $this->assertSame(101, (int) $this->probe()->insertGetId(['label' => 'after']));
    }

    public function test_a_statement_failing_after_the_rebuild_in_the_same_blueprint_keeps_the_mark(): void
    {
        // The unique index is created after the rebuilt table is renamed into place, so the rebuild has already lowered the mark.
        $this->probe()->insert([['id' => 3, 'label' => 'dup'], ['id' => 4, 'label' => 'dup']]);

        try {
            $this->migrate("Schema::table('probe', fn (Blueprint \$t) => \$t->string('label')->nullable()->unique()->change());");
            $this->fail('expected the unique index to fail');
        } catch (Throwable $e) {
            $this->assertStringContainsString('UNIQUE', $e->getMessage());
        }

        $this->assertSame(101, (int) $this->probe()->insertGetId(['label' => 'after']));
    }

    public function test_a_rollback_rebuild_keeps_the_mark(): void
    {
        $this->migrate(self::CHANGE, down: "Schema::table('probe', fn (Blueprint \$t) => \$t->string('label')->nullable(false)->change());");
        $this->probe()->where('id', $this->probe()->insertGetId(['label' => 'after']))->delete();

        Artisan::call('migrate:rollback', ['--database' => 'rowids', '--path' => $this->dir, '--realpath' => true]);

        $this->assertSame(102, (int) $this->probe()->insertGetId(['label' => 'after-rollback']));
    }

    private function migrate(string $up, string $down = ''): void
    {
        File::put($this->dir.'/2099_01_01_000000_rowids_probe.php', <<<PHP
            <?php

            use Illuminate\\Database\\Migrations\\Migration;
            use Illuminate\\Database\\Schema\\Blueprint;
            use Illuminate\\Support\\Facades\\DB;
            use Illuminate\\Support\\Facades\\Schema;

            return new class extends Migration
            {
                public function up(): void { {$up} }

                public function down(): void { {$down} }
            };
            PHP);

        Artisan::call('migrate', ['--database' => 'rowids', '--path' => $this->dir, '--realpath' => true]);
    }

    private function db(): Connection
    {
        return DB::connection('rowids');
    }

    private function probe(): Builder
    {
        return $this->db()->table('probe');
    }
}
