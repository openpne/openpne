<?php

namespace Database\Seeders;

use App\Models\Banner;
use Illuminate\Database\Seeder;

class BannerSeeder extends Seeder
{
    private const PLACEMENTS = ['top_before', 'top_after', 'side_before', 'side_after'];

    public function run(): void
    {
        foreach (self::PLACEMENTS as $name) {
            Banner::firstOrCreate(['name' => $name]);
        }
    }
}
