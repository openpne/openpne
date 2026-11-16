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

    public function test_a_dropped_sentinel_table_makes_the_next_test_migrate(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('The trait migrates once per worker on MySQL only.');
        }

        Schema::drop('openpne4_upgrade_state');

        // Run early so the flag the next test reads can be asserted; tearDown runs them again.
        $this->callBeforeApplicationDestroyedCallbacks();

        $this->assertFalse(RefreshDatabaseState::$migrated);
    }
}
