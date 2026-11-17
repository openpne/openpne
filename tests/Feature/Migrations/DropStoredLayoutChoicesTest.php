<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use App\Models\Member;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DropStoredLayoutChoicesTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): Migration
    {
        return require database_path('migrations/2026_11_17_000001_drop_stored_layout_choices.php');
    }

    public function test_the_layout_rows_go_and_every_other_row_stays(): void
    {
        $member = Member::factory()->create();
        DB::table('sns_settings')->insert([
            ['key' => 'default_look', 'value' => 'unified'],
            ['key' => 'selectable_looks', 'value' => 'standard,tabbed'],
        ]);
        DB::table('sns_settings')->updateOrInsert(['key' => 'modern_home'], ['value' => 'dashboard']);
        $another = Member::factory()->create();
        DB::table('member_preferences')->insert([
            ['member_id' => $member->getKey(), 'key' => 'preferred_look', 'value' => 'standard'],
            ['member_id' => $member->getKey(), 'key' => 'preferred_surface', 'value' => 'modern'],
            ['member_id' => $another->getKey(), 'key' => 'preferred_look', 'value' => 'unified'],
        ]);
        $settings = DB::table('sns_settings')->count();

        $this->migration()->up();

        $this->assertSame(0, DB::table('sns_settings')->whereIn('key', ['default_look', 'selectable_looks'])->count());
        $this->assertSame($settings - 2, DB::table('sns_settings')->count());
        $this->assertSame('dashboard', DB::table('sns_settings')->where('key', 'modern_home')->value('value'));
        $this->assertSame(['preferred_surface'], DB::table('member_preferences')->pluck('key')->all());
    }

    public function test_a_site_with_no_layout_rows_is_left_as_it_was(): void
    {
        $settings = DB::table('sns_settings')->count();

        $this->migration()->up();
        $this->migration()->down();

        $this->assertSame($settings, DB::table('sns_settings')->count());
    }
}
