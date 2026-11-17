<?php

namespace Tests\Feature\Upgrade;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RefreshDatabaseAfterDdlTest extends TestCase
{
    use RefreshDatabase;

    /** A RefreshDatabase test that drops a table and leaves the flag set hands the next once-migrated test a schema without it. */
    public function test_a_table_dropped_on_mysql_makes_the_next_test_migrate(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Only MySQL ends the transaction on DDL.');
        }

        Schema::drop('openpne4_upgrade_state');

        $this->callBeforeApplicationDestroyedCallbacks();

        $this->assertFalse(RefreshDatabaseState::$migrated);
    }
}
