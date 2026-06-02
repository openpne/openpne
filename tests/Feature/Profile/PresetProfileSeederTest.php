<?php

namespace Tests\Feature\Profile;

use App\Models\Profile;
use Database\Seeders\PresetProfileSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PresetProfileSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_registers_standard_presets_with_localised_captions(): void
    {
        $this->seed(PresetProfileSeeder::class);

        $sex = Profile::where('name', 'op_preset_sex')->firstOrFail();
        $this->assertSame('select', $sex->form_type);
        $this->assertSame('性別', $sex->getCaption('ja_JP'));
        $this->assertSame('Sex', $sex->getCaption('en'));

        // Region variants are admin-chosen, not seeded.
        $this->assertDatabaseMissing('profiles', ['name' => 'op_preset_region']);
    }

    public function test_every_seeded_profile_has_a_valid_default_public_flag(): void
    {
        $this->seed(PresetProfileSeeder::class);

        // OpenPNE's catalog default is 0; the seeder must normalise it to a real 1-4 flag.
        $this->assertSame(0, Profile::whereNotIn('default_public_flag', [1, 2, 3, 4])->count());
        $this->assertGreaterThan(0, Profile::count());
    }

    public function test_seeding_is_idempotent(): void
    {
        $this->seed(PresetProfileSeeder::class);
        $count = Profile::count();
        $this->seed(PresetProfileSeeder::class);

        $this->assertSame($count, Profile::count());
    }
}
