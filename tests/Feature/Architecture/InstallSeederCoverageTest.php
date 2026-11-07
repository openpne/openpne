<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\Runner\UpgradeRunner;
use App\Upgrade\StepRegistry;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\InstallSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * A seeder outside InstallSeeder::SEEDERS is one a new site never gets (docs/internals/runtime.md, "Initial data").
 */
class InstallSeederCoverageTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_seeder_is_called_by_the_install_entry(): void
    {
        $entries = [DatabaseSeeder::class, InstallSeeder::class];

        foreach (File::files(database_path('seeders')) as $file) {
            $class = 'Database\\Seeders\\'.$file->getFilenameWithoutExtension();
            if (in_array($class, $entries, true)) {
                continue;
            }

            $this->assertContains($class, InstallSeeder::SEEDERS, "{$class} is not in InstallSeeder::SEEDERS");
        }
    }

    public function test_every_upgrade_target_the_install_writes_is_one_the_upgrade_checks(): void
    {
        $targets = (new UpgradeRunner(new InsertSelectCompiler, StepRegistry::all()))->targetTables();
        $before = array_filter($targets, static fn (string $table): bool => DB::table($table)->exists());

        $this->seed(InstallSeeder::class);

        $written = array_values(array_filter($targets, static fn (string $table): bool => ! in_array($table, $before, true) && DB::table($table)->exists()));

        $this->assertNotEmpty($written);
        $this->assertSame([], array_values(array_diff($written, UpgradeRunner::INSTALL_TABLES)), 'an installed table the upgrade would collide on is not checked');
        $this->assertSame([], array_values(array_diff(UpgradeRunner::INSTALL_TABLES, $targets)), 'INSTALL_TABLES names a table no step targets');
    }
}
