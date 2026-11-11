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
        config(['openpne.security.operator_raw_html' => true]);

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
        config(['openpne.security.operator_raw_html' => true]);

        $this->assertSame('', app(SnsSettingService::class)->get(SnsSettingKey::PcHtmlHead));

        Livewire::test(DesignSettings::class)
            ->fillForm(['pc_html_head' => '<meta name="a" content="b">'])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('<meta name="a" content="b">', app(SnsSettingService::class)->get(SnsSettingKey::PcHtmlHead));
    }

    public function test_page_exposes_the_design_settings_group_without_the_markdown_counterparts(): void
    {
        config(['openpne.security.operator_raw_html' => true]);

        $data = Livewire::test(DesignSettings::class)->get('data');

        $actual = array_keys($data);
        sort($actual);

        $expected = array_map(
            fn (SnsSettingKey $key): string => $key->value,
            array_filter(SnsSettingKey::inGroup(SettingGroup::Design), fn (SnsSettingKey $key): bool => ! $key->isOperatorMarkdown()),
        );
        sort($expected);

        $this->assertSame($expected, $actual);
        $this->assertNotContains('footer_after_markdown', $actual);
    }

    public function test_saving_leaves_a_stored_markdown_counterpart_alone(): void
    {
        config(['openpne.security.operator_raw_html' => true]);

        DB::table('sns_settings')->insert(['key' => 'footer_after_markdown', 'value' => '管理者の文']);

        Livewire::test(DesignSettings::class)
            ->fillForm(['footer_after' => '<b>HTML</b>'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('sns_settings', ['key' => 'footer_after', 'value' => '<b>HTML</b>']);
        $this->assertDatabaseHas('sns_settings', ['key' => 'footer_after_markdown', 'value' => '管理者の文']);
        $this->assertDatabaseMissing('sns_settings', ['key' => 'insert_top_markdown']);
    }
}
