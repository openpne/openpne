<?php

namespace Database\Seeders;

use App\Models\Banner;
use Illuminate\Database\Seeder;

/**
 * The PC banner placements, top only: the side placements are gadgets, and the mobile frontend is
 * out of scope. The placements start empty (image mode, no images); operators fill them in admin.
 */
class BannerSeeder extends Seeder
{
    /** The placements rendered in the PC #topBanner (before / after login). */
    private const PLACEMENTS = ['top_before', 'top_after'];

    public function run(): void
    {
        foreach (self::PLACEMENTS as $name) {
            Banner::firstOrCreate(['name' => $name]);
        }
    }
}
