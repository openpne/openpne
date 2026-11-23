<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class ConvertTextColumnsOnMariaDbTest extends TestCase
{
    public function test_migrate_stops_at_it_on_mariadb_and_leaves_it_to_run_again(): void
    {
        if (DB::connection()->getDriverName() !== 'mariadb') {
            $this->markTestSkipped('mariadb only');
        }

        Schema::dropAllTables();

        $stopped = null;
        try {
            Artisan::call('migrate', ['--force' => true]);
        } catch (RuntimeException $e) {
            $stopped = $e;
        }

        $this->assertNotNull($stopped, 'migrate went past the text collation conversion');
        $this->assertStringContainsString('does not convert MariaDB yet', $stopped->getMessage());

        $ran = DB::table('migrations')->orderBy('id')->pluck('migration')->all();
        $this->assertSame('2026_11_18_000001_convert_timestamp_columns_to_datetime', end($ran));

        Schema::dropAllTables();
    }
}
