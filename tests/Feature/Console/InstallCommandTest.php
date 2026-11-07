<?php

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InstallCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_a_fresh_site_and_names_the_next_step(): void
    {
        $this->artisan('openpne:install')
            ->expectsOutputToContain('openpne:admin:create')
            ->assertSuccessful();

        foreach (['profiles', 'navigations', 'gadgets', 'banners'] as $table) {
            $this->assertTrue(DB::table($table)->exists(), "{$table} is seeded");
        }
    }

    public function test_a_second_run_succeeds_without_adding_rows(): void
    {
        $this->artisan('openpne:install')->assertSuccessful();
        $navigations = DB::table('navigations')->count();

        $this->artisan('openpne:install')
            ->expectsOutputToContain('already initialised')
            ->assertSuccessful();

        $this->assertSame($navigations, DB::table('navigations')->count());
    }
}
