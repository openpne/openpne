<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Pages\BannerSettings;
use App\Filament\Pages\DesignSettings;
use App\Filament\Resources\Gadgets\Pages\CreateGadget;
use App\Filament\Resources\Gadgets\Pages\EditGadget;
use App\Models\Banner;
use App\Models\Gadget;
use App\Models\GadgetConfig;
use App\Services\SnsSettingService;
use App\Support\SnsSettingKey;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class OperatorMarkdownAdminTest extends TestCase
{
    use RefreshDatabase;

    private const SCRIPT = '<script>alert(1)</script>';

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('sns_settings')->truncate();
        app(SnsSettingService::class)->clearCache();

        Filament::setCurrentPanel('admin');
        $this->actingAsAdmin();
    }

    public function test_the_design_page_edits_the_markdown_keys_and_not_the_html_ones(): void
    {
        $page = Livewire::test(DesignSettings::class);

        foreach (SnsSettingKey::cases() as $key) {
            if ($key->isOperatorMarkdown() || $key === SnsSettingKey::CustomCss) {
                $page->assertFormFieldExists($key->value);
            } elseif ($key->markdownCounterpart() !== null || $key === SnsSettingKey::PcHtmlHead) {
                $page->assertFormFieldDoesNotExist($key->value);
            }
        }

        $page->assertSee(__('Page inserts'))->assertDontSee(__('HTML insertion'))->assertDontSee(__('Previous HTML'));
    }

    public function test_saving_the_design_page_writes_the_markdown_and_leaves_the_html_rows(): void
    {
        $this->setSnsSetting(SnsSettingKey::PcHtmlTop, '<b>old</b>');
        $this->setSnsSetting(SnsSettingKey::PcHtmlHead, self::SCRIPT);

        Livewire::test(DesignSettings::class)
            ->fillForm(['insert_top_markdown' => '**new**', 'footer_after_markdown' => ''])
            ->set('data.previous_pc_html_top', 'tampered')
            ->set('data.pc_html_head', 'tampered')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('sns_settings', ['key' => 'insert_top_markdown', 'value' => '**new**']);
        $this->assertDatabaseHas('sns_settings', ['key' => 'footer_after_markdown', 'value' => '']);
        $this->assertDatabaseHas('sns_settings', ['key' => 'pc_html_top', 'value' => '<b>old</b>']);
        $this->assertDatabaseHas('sns_settings', ['key' => 'pc_html_head', 'value' => self::SCRIPT]);
        $this->assertDatabaseMissing('sns_settings', ['key' => 'footer_after']);
    }

    public function test_the_design_page_shows_stored_html_read_only_and_escaped(): void
    {
        $this->setSnsSetting(SnsSettingKey::PcHtmlHead, self::SCRIPT);
        $this->setSnsSetting(SnsSettingKey::PcHtmlTop, '');

        Livewire::test(DesignSettings::class)
            ->assertSee(__('Previous HTML'))
            ->assertFormFieldExists('previous_pc_html_head')
            ->assertFormFieldDisabled('previous_pc_html_head')
            ->assertFormFieldDoesNotExist('previous_pc_html_top')
            ->assertFormFieldDoesNotExist('previous_footer_after')
            ->assertSchemaStateSet(['previous_pc_html_head' => self::SCRIPT])
            ->assertDontSee(self::SCRIPT, false);
    }

    public function test_the_banner_page_saves_markdown_and_leaves_the_html(): void
    {
        Banner::create(['name' => 'top_after', 'is_use_html' => true, 'html' => self::SCRIPT, 'markdown' => 'old']);

        Livewire::test(BannerSettings::class)
            ->assertSee(__('Use custom text'))
            ->assertDontSee(__('Use custom HTML'))
            ->assertFormFieldDisabled('top_after_html')
            ->assertDontSee(self::SCRIPT, false)
            ->fillForm(['top_after_markdown' => '**new**'])
            ->set('data.top_after_html', 'tampered')
            ->call('save')
            ->assertHasNoErrors();

        $banner = Banner::where('name', 'top_after')->firstOrFail();
        $this->assertSame('**new**', $banner->markdown);
        $this->assertSame(self::SCRIPT, $banner->html);
    }

    public function test_an_emptied_banner_text_is_stored_as_empty_not_null(): void
    {
        Banner::create(['name' => 'top_after', 'is_use_html' => true, 'html' => '<b>old</b>', 'markdown' => 'old']);

        Livewire::test(BannerSettings::class)
            ->fillForm(['top_after_markdown' => ''])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('', Banner::where('name', 'top_after')->value('markdown'));
    }

    /** A save before the conversion must not mark the untouched values as converted. */
    public function test_saving_leaves_values_nobody_wrote_unconverted(): void
    {
        $this->setSnsSetting(SnsSettingKey::FooterAfter, '<b>old</b>');
        Banner::create(['name' => 'top_after', 'is_use_html' => true, 'html' => '<b>old</b>']);
        Banner::create(['name' => 'top_before', 'is_use_html' => true, 'html' => '<b>old</b>']);

        Livewire::test(DesignSettings::class)->fillForm(['insert_top_markdown' => 'new'])->call('save')->assertHasNoErrors();
        Livewire::test(BannerSettings::class)->fillForm(['top_before_markdown' => 'new'])->call('save')->assertHasNoErrors();

        $this->assertDatabaseHas('sns_settings', ['key' => 'insert_top_markdown', 'value' => 'new']);
        $this->assertSame(1, DB::table('sns_settings')->where('key', 'like', '%_markdown')->count());
        $this->assertSame('new', Banner::where('name', 'top_before')->value('markdown'));
        $this->assertNull(Banner::where('name', 'top_after')->value('markdown'));
    }

    public function test_a_stored_footer_set_back_to_the_default_is_saved(): void
    {
        $this->setSnsSetting(SnsSettingKey::FooterAfterMarkdown, 'custom');

        Livewire::test(DesignSettings::class)
            ->fillForm(['footer_after_markdown' => SnsSettingKey::FooterAfterMarkdown->default()])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('sns_settings', ['key' => 'footer_after_markdown', 'value' => SnsSettingKey::FooterAfterMarkdown->default()]);
    }

    /** The first two have only the HTML row, as raw-HTML mode leaves them, and their save changes nothing but the order. */
    public function test_saving_a_gadget_leaves_a_body_nobody_wrote_unconverted(): void
    {
        $info = Gadget::create(['context' => 'home', 'zone' => 'contents', 'name' => 'informationBox', 'sort_order' => 0]);
        GadgetConfig::create(['gadget_id' => $info->id, 'name' => 'value', 'value' => '<b>info</b>']);
        $free = Gadget::create(['context' => 'home', 'zone' => 'contents', 'name' => 'freeArea', 'sort_order' => 1]);
        GadgetConfig::create(['gadget_id' => $free->id, 'name' => 'title', 'value' => 'Notice']);
        GadgetConfig::create(['gadget_id' => $free->id, 'name' => 'value', 'value' => '<b>free</b>']);
        $emptied = Gadget::create(['context' => 'sidebanner', 'zone' => 'contents', 'name' => 'informationBox', 'sort_order' => 2]);
        GadgetConfig::create(['gadget_id' => $emptied->id, 'name' => 'value', 'value' => '<b>emptied</b>']);
        GadgetConfig::create(['gadget_id' => $emptied->id, 'name' => 'value_markdown', 'value' => 'old']);

        foreach ([$info, $free] as $gadget) {
            Livewire::test(EditGadget::class, ['record' => $gadget->getKey()])
                ->fillForm(['sort_order' => 20])
                ->call('save')
                ->assertHasNoFormErrors();
        }
        Livewire::test(EditGadget::class, ['record' => $emptied->getKey()])
            ->fillForm(['config_value_markdown' => ''])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->artisan('openpne:convert-operator-html')->assertSuccessful();

        $this->assertDatabaseHas('gadget_configs', ['gadget_id' => $info->id, 'name' => 'value_markdown', 'value' => '**info**']);
        $this->assertDatabaseHas('gadget_configs', ['gadget_id' => $free->id, 'name' => 'value_markdown', 'value' => '**free**']);
        $this->assertDatabaseHas('gadget_configs', ['gadget_id' => $free->id, 'name' => 'title', 'value' => 'Notice']);
        $this->assertSame(1, GadgetConfig::where(['gadget_id' => $emptied->id, 'name' => 'value_markdown'])->count());
        $this->assertSame('', (string) GadgetConfig::where(['gadget_id' => $emptied->id, 'name' => 'value_markdown'])->value('value'));
    }

    public function test_oversized_markdown_is_rejected(): void
    {
        Banner::create(['name' => 'top_after', 'is_use_html' => true]);

        Livewire::test(BannerSettings::class)
            ->fillForm(['top_after_markdown' => str_repeat('a', 65536)])
            ->call('save')
            ->assertHasErrors('data.top_after_markdown');

        Livewire::test(CreateGadget::class)
            ->fillForm(['context' => 'home', 'name' => 'freeArea', 'zone' => 'contents', 'sort_order' => 10, 'config_value_markdown' => str_repeat('a', 65536)])
            ->call('create')
            ->assertHasFormErrors(['config_value_markdown']);
    }

    public function test_a_gadget_is_created_with_a_markdown_body(): void
    {
        Livewire::test(CreateGadget::class)
            ->fillForm(['context' => 'home', 'name' => 'freeArea', 'zone' => 'contents', 'sort_order' => 10, 'config_title' => 'Notice', 'config_value_markdown' => '**Hello**'])
            ->assertFormFieldDoesNotExist('config_value')
            ->call('create')
            ->assertHasNoFormErrors();

        $gadget = Gadget::query()->where('name', 'freeArea')->firstOrFail();
        $this->assertDatabaseHas('gadget_configs', ['gadget_id' => $gadget->id, 'name' => 'value_markdown', 'value' => '**Hello**']);
        $this->assertDatabaseMissing('gadget_configs', ['gadget_id' => $gadget->id, 'name' => 'value']);
    }

    public function test_editing_a_gadget_shows_its_html_read_only_and_leaves_it(): void
    {
        $gadget = Gadget::create(['context' => 'home', 'zone' => 'contents', 'name' => 'informationBox', 'sort_order' => 0]);
        GadgetConfig::create(['gadget_id' => $gadget->id, 'name' => 'value', 'value' => self::SCRIPT]);
        GadgetConfig::create(['gadget_id' => $gadget->id, 'name' => 'value_markdown', 'value' => 'old']);

        Livewire::test(EditGadget::class, ['record' => $gadget->getKey()])
            ->assertFormFieldDisabled('config_value')
            ->assertSchemaStateSet(['config_value' => self::SCRIPT])
            ->assertDontSee(self::SCRIPT, false)
            ->fillForm(['config_value_markdown' => 'new'])
            ->set('data.config_value', 'tampered')
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('gadget_configs', ['gadget_id' => $gadget->id, 'name' => 'value_markdown', 'value' => 'new']);
        $this->assertDatabaseHas('gadget_configs', ['gadget_id' => $gadget->id, 'name' => 'value', 'value' => self::SCRIPT]);
    }

    public function test_only_the_home_information_box_points_at_the_modern_notice_in_either_mode(): void
    {
        app()->setLocale('en');
        $pointer = 'The Modern home shows a notice of its own';
        $home = Gadget::create(['context' => 'home', 'zone' => 'top', 'name' => 'informationBox', 'sort_order' => 0]);
        $freeArea = Gadget::create(['context' => 'home', 'zone' => 'top', 'name' => 'freeArea', 'sort_order' => 1]);
        $sideBanner = Gadget::create(['context' => 'sidebanner', 'zone' => 'contents', 'name' => 'informationBox', 'sort_order' => 0]);

        foreach ([false, true] as $raw) {
            config(['openpne.security.operator_raw_html' => $raw, 'openpne.surface_mode' => 'classic_default']);

            Livewire::test(EditGadget::class, ['record' => $home->getKey()])->assertSee($pointer);
            Livewire::test(EditGadget::class, ['record' => $freeArea->getKey()])->assertDontSee($pointer);
            Livewire::test(EditGadget::class, ['record' => $sideBanner->getKey()])->assertDontSee($pointer);

            config(['openpne.surface_mode' => 'classic_only']);
            Livewire::test(EditGadget::class, ['record' => $home->getKey()])->assertDontSee($pointer);
        }
    }
}
