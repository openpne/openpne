<?php

namespace Database\Seeders;

use App\Services\GadgetService;
use App\Services\NavigationService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds only a site with no navigation, gadget or profile rows at all, since a partly filled site is
 * one an administrator has edited (docs/internals/runtime.md, "Initial data").
 */
class InstallSeeder extends Seeder
{
    /** @var list<class-string<Seeder>> */
    public const SEEDERS = [
        PresetProfileSeeder::class,
        NavigationSeeder::class,
        GadgetSeeder::class,
        BannerSeeder::class,
    ];

    public const GUARD_TABLES = ['navigations', 'gadgets', 'profiles'];

    public function run(): void
    {
        foreach (self::GUARD_TABLES as $table) {
            if (DB::table($table)->exists()) {
                $this->command?->warn("Site already initialised ({$table} has rows); nothing seeded.");

                return;
            }
        }

        DB::transaction(function (): void {
            $this->call(self::SEEDERS);
        });

        // A request served during the transaction may have cached the empty tables.
        app(NavigationService::class)->clearCache();
        app(GadgetService::class)->clearCache();
    }
}
