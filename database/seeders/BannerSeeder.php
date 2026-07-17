<?php

namespace Database\Seeders;

use App\Models\Banner;
use Illuminate\Database\Seeder;

/**
 * The PC banner placements, top (#topBanner) and side (the sideBanner gadget); the mobile frontend is
 * out of scope. The placements start empty (image mode, no images); operators fill them in admin.
 */
class BannerSeeder extends Seeder
{
    /** The placements rendered on PC (top and side, before / after login). */
    private const PLACEMENTS = ['top_before', 'top_after', 'side_before', 'side_after'];

    public function run(): void
    {
        foreach (self::PLACEMENTS as $name) {
            Banner::firstOrCreate(['name' => $name]);
        }
    }
}
