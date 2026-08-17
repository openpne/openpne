<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use App\Services\SnsSettingService;
use App\Support\Look;
use App\Support\SnsSettingKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The `modern_unified_home` → `default_look` conversion, both ways. The old key exists here only as
 * the literal the migration itself carries — its enum case is gone.
 */
class ConvertModernUnifiedHomeToDefaultLookTest extends TestCase
{
    use RefreshDatabase;

    private const OLD_KEY = 'modern_unified_home';

    private function migration(): Migration
    {
        return require database_path('migrations/2026_08_18_000000_convert_modern_unified_home_to_default_look.php');
    }

    private function storedLook(): ?string
    {
        return DB::table('sns_settings')->where('key', SnsSettingKey::DefaultLook->value)->value('value');
    }

    private function storedOldKey(): ?string
    {
        return DB::table('sns_settings')->where('key', self::OLD_KEY)->value('value');
    }

    /**
     * The site that had the experiment on renders unified on the very next read — no cache clear of
     * the test's own. A migration is the one `sns_settings` writer that does not go through
     * SnsSettingService::clearCache(), so without its own forget a warm map would hold the site on
     * standard until the hour was up.
     */
    public function test_the_experiment_carries_over_through_a_warm_cache(): void
    {
        DB::table('sns_settings')->updateOrInsert(['key' => self::OLD_KEY], ['value' => '1']);
        $this->assertSame(Look::Standard, app(SnsSettingService::class)->get(SnsSettingKey::DefaultLook));

        $this->migration()->up();

        $this->assertSame(Look::Unified, app(SnsSettingService::class)->get(SnsSettingKey::DefaultLook));
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
        DB::table('sns_settings')->updateOrInsert(['key' => SnsSettingKey::DefaultLook->value], ['value' => 'unified']);

        $this->migration()->down();

        $this->assertSame('1', $this->storedOldKey());
        $this->assertNull($this->storedLook());
    }

    public function test_down_writes_no_old_row_for_a_look_that_is_not_the_experiment(): void
    {
        DB::table('sns_settings')->updateOrInsert(['key' => SnsSettingKey::DefaultLook->value], ['value' => 'standard']);

        $this->migration()->down();

        $this->assertNull($this->storedOldKey());
        $this->assertNull($this->storedLook());
    }
}
