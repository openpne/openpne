<?php

namespace Tests\Feature\Database;

use App\Models\Banner;
use App\Models\Navigation;
use Database\Seeders\GadgetSeeder;
use Database\Seeders\InstallSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class InstallSeederTest extends TestCase
{
    use RefreshDatabase;

    private const TABLES = ['profiles', 'navigations', 'gadgets', 'banners'];

    public function test_an_empty_site_gets_every_default_set(): void
    {
        $this->seed(InstallSeeder::class);

        foreach (self::TABLES as $table) {
            $this->assertTrue(DB::table($table)->exists(), "{$table} is seeded");
        }
    }

    public function test_a_second_run_adds_nothing(): void
    {
        $this->seed(InstallSeeder::class);
        $counts = $this->counts();

        $this->seed(InstallSeeder::class);

        $this->assertSame($counts, $this->counts());
    }

    public function test_a_site_with_any_navigation_row_is_left_alone(): void
    {
        Navigation::create(['type' => 'default', 'uri' => '/custom', 'sort_order' => 0]);

        $this->seed(InstallSeeder::class);

        $this->assertSame(1, Navigation::count());
        foreach (['profiles', 'gadgets', 'banners'] as $table) {
            $this->assertFalse(DB::table($table)->exists(), "{$table} stays empty");
        }
    }

    public function test_banner_rows_alone_do_not_count_as_initialised(): void
    {
        // The admin Banner page creates these four on first open.
        foreach (['top_before', 'top_after', 'side_before', 'side_after'] as $name) {
            Banner::firstOrCreate(['name' => $name]);
        }

        $this->seed(InstallSeeder::class);

        foreach (['profiles', 'navigations', 'gadgets'] as $table) {
            $this->assertTrue(DB::table($table)->exists(), "{$table} is seeded");
        }
        $this->assertSame(4, Banner::count());
    }

    public function test_a_failing_seeder_leaves_no_partial_state(): void
    {
        $this->app->bind(GadgetSeeder::class, fn () => new class extends Seeder
        {
            public function run(): void
            {
                throw new RuntimeException('gadget seeding failed');
            }
        });

        try {
            $this->seed(InstallSeeder::class);
            $this->fail('the failure propagates');
        } catch (RuntimeException) {
        }

        foreach (self::TABLES as $table) {
            $this->assertFalse(DB::table($table)->exists(), "{$table} is rolled back");
        }
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        $counts = [];
        foreach (self::TABLES as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }
}
