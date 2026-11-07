<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Database\Seeders\DatabaseSeeder;
use Database\Seeders\InstallSeeder;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * A seeder outside InstallSeeder::SEEDERS is one a new site never gets (docs/internals/runtime.md, "Initial data").
 */
class InstallSeederCoverageTest extends TestCase
{
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
}
