<?php

namespace Tests\Feature\Profile;

use App\Models\Profile;
use Database\Seeders\PresetProfileSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PresetProfileSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_site_starts_with_birthday_and_self_introduction_both_optional(): void
    {
        $this->seed(PresetProfileSeeder::class);

        $this->assertSame(
            ['op_preset_birthday', 'op_preset_self_introduction'],
            Profile::query()->orderBy('sort_order')->pluck('name')->all(),
        );
        $this->assertSame(0, Profile::query()->where('is_required', true)->count());

        $birthday = Profile::where('name', 'op_preset_birthday')->firstOrFail();
        $this->assertSame('date', $birthday->form_type);
        $this->assertSame('誕生日', $birthday->getCaption('ja_JP'));
        $this->assertSame('Birthday', $birthday->getCaption('en'));
    }

    public function test_every_seeded_profile_has_a_valid_default_visibility(): void
    {
        $this->seed(PresetProfileSeeder::class);

        // OpenPNE's catalog default is 0; the seeder maps it onto the Visibility scale (0-3).
        $this->assertSame(0, Profile::whereNotIn('default_visibility', [0, 1, 2, 3])->count());
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
