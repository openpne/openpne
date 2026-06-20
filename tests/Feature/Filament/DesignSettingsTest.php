<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Pages\DesignSettings;
use App\Models\AdminUser;
use App\Services\SnsSettingService;
use App\Support\SettingGroup;
use App\Support\SnsSettingKey;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The design-settings editor (custom CSS, PC HTML insertion slots, footer). `sns_settings` is
 * authoritative: design values are stored verbatim and, unlike the identity fields, are NOT trimmed,
 * and the page only ever exposes its own (Design) group.
 */
class DesignSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('sns_settings')->truncate();
        app(SnsSettingService::class)->clearCache();

        Filament::setCurrentPanel('admin');
        $this->actingAs(AdminUser::factory()->create(), 'admin');
    }

    public function test_saving_stores_every_design_field_verbatim(): void
    {
        Livewire::test(DesignSettings::class)
            ->fillForm([
                'customizing_css' => '#logo { color: red; }',
                'pc_html_head' => '<meta name="x" content="y">',
                'footer_before' => 'Guest footer',
                'footer_after' => 'Member footer',
            ])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('sns_settings', ['key' => 'customizing_css', 'value' => '#logo { color: red; }']);
        $this->assertDatabaseHas('sns_settings', ['key' => 'pc_html_head', 'value' => '<meta name="x" content="y">']);
        $this->assertDatabaseHas('sns_settings', ['key' => 'footer_before', 'value' => 'Guest footer']);
        $this->assertDatabaseHas('sns_settings', ['key' => 'footer_after', 'value' => 'Member footer']);
    }

    public function test_design_values_are_not_trimmed(): void
    {
        // Whitespace is significant: a stylesheet's @charset must be the first byte, and OpenPNE 3
        // stored these with trimming disabled.
        $css = "\n@charset \"UTF-8\";\nbody { margin: 0; }\n";

        Livewire::test(DesignSettings::class)
            ->fillForm(['customizing_css' => $css])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame($css, DB::table('sns_settings')->where('key', 'customizing_css')->value('value'));
    }

    public function test_oversized_value_is_rejected(): void
    {
        // One byte over the TEXT column limit.
        Livewire::test(DesignSettings::class)
            ->fillForm(['customizing_css' => str_repeat('a', 65536)])
            ->call('save')
            ->assertHasErrors('data.customizing_css');
    }

    public function test_save_takes_effect_on_the_service(): void
    {
        $this->assertSame('', app(SnsSettingService::class)->get(SnsSettingKey::PcHtmlHead));

        Livewire::test(DesignSettings::class)
            ->fillForm(['pc_html_head' => '<meta name="a" content="b">'])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('<meta name="a" content="b">', app(SnsSettingService::class)->get(SnsSettingKey::PcHtmlHead));
    }

    public function test_page_only_exposes_the_design_settings_group(): void
    {
        $data = Livewire::test(DesignSettings::class)->get('data');

        $actual = array_keys($data);
        sort($actual);

        $expected = array_map(
            fn (SnsSettingKey $key): string => $key->value,
            SnsSettingKey::inGroup(SettingGroup::Design),
        );
        sort($expected);

        $this->assertSame($expected, $actual);
    }
}
