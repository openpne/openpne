<?php

namespace Tests\Feature\Upgrade;

use App\Upgrade\SourceSchema;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MigratesUpgradeTargetsOnce;
use Tests\TestCase;

/**
 * A test that runs upgrade SQL over the OpenPNE 3 source DDL: MySQL only, and sourceTables() are
 * created from that DDL before each method and dropped after it. Creating them is DDL that commits,
 * so the targets are migrated once per worker rather than wrapped in RefreshDatabase's transaction.
 */
abstract class UpgradeSqlTestCase extends TestCase
{
    use MigratesUpgradeTargetsOnce;

    /** @return list<string> OpenPNE 3 tables in creation order, each created without its foreign keys. */
    protected function sourceTables(): array
    {
        return [];
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('The OpenPNE 3 source DDL and the upgrade SQL run on MySQL.');
        }

        $this->dropSourceTables();
        $this->createSourceTables();
    }

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            $this->dropSourceTables();
        }

        parent::tearDown();
    }

    protected function createSourceTables(): void
    {
        foreach ($this->sourceTables() as $table) {
            DB::statement(SourceSchema::default()->createStatement($table, withoutForeignKeys: true));
        }
    }

    protected function dropSourceTables(): void
    {
        foreach (array_reverse($this->sourceTables()) as $table) {
            DB::statement("DROP TABLE IF EXISTS `{$table}`");
        }
    }
}
