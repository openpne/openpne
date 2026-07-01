<?php

namespace Tests\Feature\Upgrade\Verify;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class VerifyUpgradeCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_command_requires_mysql(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            $this->markTestSkipped('The driver guard only fires off MySQL.');
        }

        $this->artisan('openpne:verify-upgrade')
            ->expectsOutputToContain('requires MySQL')
            ->assertFailed();
    }

    public function test_it_rejects_an_invalid_source_prefix(): void
    {
        $this->artisan('openpne:verify-upgrade', ['--source-prefix' => 'bad-prefix!'])
            ->expectsOutputToContain('--source-prefix must match')
            ->assertFailed();
    }

    public function test_it_rejects_an_invalid_source_database(): void
    {
        $this->artisan('openpne:verify-upgrade', ['--source-database' => 'bad db!'])
            ->expectsOutputToContain('--source-database must match')
            ->assertFailed();
    }
}
