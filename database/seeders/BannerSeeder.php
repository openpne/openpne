<?php

namespace Database\Seeders;

use App\Models\Banner;
use Illuminate\Database\Seeder;

/**
 * OpenPNE 3's PC banner placements (data/fixtures/009_import_banner.yml), top only: the PC side
 * placements were gadgets (already ported) and the op_banner side partial was mobile-frontend, which
 * is out of scope. The placements start empty (image mode, no images); operators fill them in admin.
 */
class BannerSeeder extends Seeder
{
    /** OpenPNE 3 banner.name values rendered in the PC #topBanner (before / after login). */
    private const PLACEMENTS = ['top_before', 'top_after'];

    public function run(): void
    {
        foreach (self::PLACEMENTS as $name) {
            Banner::firstOrCreate(['name' => $name]);
        }
    }
}
