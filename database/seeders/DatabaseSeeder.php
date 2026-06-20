<?php

namespace Database\Seeders;

use App\Models\Member;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call(PresetProfileSeeder::class);
        $this->call(NavigationSeeder::class);
        $this->call(GadgetSeeder::class);

        Member::factory()->create([
            'name' => 'Test Member',
            'email' => 'test@example.com',
        ]);
    }
}
