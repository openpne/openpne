<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Both keys exist here only as the literals the migration itself carries: their enum cases are gone. */
class ConvertModernUnifiedHomeToDefaultLookTest extends TestCase
{
    use RefreshDatabase;

    private const OLD_KEY = 'modern_unified_home';

    private const NEW_KEY = 'default_look';

    private function migration(): Migration
    {
        return require database_path('migrations/2026_08_18_000000_convert_modern_unified_home_to_default_look.php');
    }

    private function storedLook(): ?string
    {
        return DB::table('sns_settings')->where('key', self::NEW_KEY)->value('value');
    }

    private function storedOldKey(): ?string
    {
        return DB::table('sns_settings')->where('key', self::OLD_KEY)->value('value');
    }

    public function test_the_experiment_carries_over(): void
    {
        DB::table('sns_settings')->updateOrInsert(['key' => self::OLD_KEY], ['value' => '1']);

        $this->migration()->up();

        $this->assertSame('unified', $this->storedLook());
        $this->assertNull($this->storedOldKey());
    }

    public function test_an_explicitly_off_site_is_left_on_the_absent_row_default(): void
    {
        DB::table('sns_settings')->updateOrInsert(['key' => self::OLD_KEY], ['value' => '0']);

        $this->migration()->up();

        $this->assertNull($this->storedLook());
        $this->assertNull($this->storedOldKey());
    }

    public function test_down_restores_the_old_switch_only_for_the_look_it_converted(): void
    {
        DB::table('sns_settings')->updateOrInsert(['key' => self::NEW_KEY], ['value' => 'unified']);

        $this->migration()->down();

        $this->assertSame('1', $this->storedOldKey());
        $this->assertNull($this->storedLook());
    }

    public function test_down_writes_no_old_row_for_a_look_that_is_not_the_experiment(): void
    {
        DB::table('sns_settings')->updateOrInsert(['key' => self::NEW_KEY], ['value' => 'standard']);

        $this->migration()->down();

        $this->assertNull($this->storedOldKey());
        $this->assertNull($this->storedLook());
    }
}
