<?php

namespace Tests\Feature\Upgrade;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RefreshDatabaseAfterDdlTest extends TestCase
{
    use RefreshDatabase;

    public function test_ddl_on_mysql_makes_the_next_test_migrate(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('The once-migrated trait this is held for runs on MySQL only.');
        }

        DB::statement('CREATE TABLE refresh_database_after_ddl (id INT)');
        DB::statement('DROP TABLE refresh_database_after_ddl');

        $this->callBeforeApplicationDestroyedCallbacks();

        $this->assertFalse(RefreshDatabaseState::$migrated);
    }
}
