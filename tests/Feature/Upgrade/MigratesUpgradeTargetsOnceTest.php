<?php

namespace Tests\Feature\Upgrade;

use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\MigratesUpgradeTargetsOnce;
use Tests\TestCase;

class MigratesUpgradeTargetsOnceTest extends TestCase
{
    use MigratesUpgradeTargetsOnce;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('The trait migrates once per worker on MySQL only.');
        }
    }

    public function test_an_intact_schema_stays_migrated_for_the_next_test(): void
    {
        $this->runTeardownCallbacks();

        $this->assertTrue(RefreshDatabaseState::$migrated);
    }

    public function test_a_dropped_sentinel_table_makes_the_next_test_migrate(): void
    {
        Schema::drop('openpne4_upgrade_state');

        $this->runTeardownCallbacks();

        $this->assertFalse(RefreshDatabaseState::$migrated);
    }

    /** Run early so the flag the next test reads can be asserted; tearDown runs them again. */
    private function runTeardownCallbacks(): void
    {
        $this->callBeforeApplicationDestroyedCallbacks();
    }
}
