<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Pages\LandingPageSettings;
use App\Services\SnsSettingService;
use App\Support\ModernGroupList;
use App\Support\ModernHome;
use App\Support\SnsSettingKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class LandingPageSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsAdmin();
    }

    public function test_the_home_page_is_the_issue_until_saved_and_round_trips(): void
    {
        Livewire::test(LandingPageSettings::class)
            ->assertSet('data.'.SnsSettingKey::ModernHome->value, 'issue')
            ->set('data.'.SnsSettingKey::ModernHome->value, 'whats_new')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('data.'.SnsSettingKey::ModernHome->value, 'whats_new');

        $this->assertSame('whats_new', DB::table('sns_settings')->where('key', SnsSettingKey::ModernHome->value)->value('value'));
        $this->assertSame(ModernHome::WhatsNew, app(SnsSettingService::class)->get(SnsSettingKey::ModernHome));

        Livewire::test(LandingPageSettings::class)
            ->set('data.'.SnsSettingKey::ModernHome->value, 'nope')
            ->call('save')
            ->assertHasErrors('data.'.SnsSettingKey::ModernHome->value);
    }

    public function test_a_joined_group_row_opens_the_talk_until_saved_and_round_trips(): void
    {
        Livewire::test(LandingPageSettings::class)
            ->assertSet('data.'.SnsSettingKey::ModernGroupList->value, 'talk')
            ->set('data.'.SnsSettingKey::ModernGroupList->value, 'group')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('data.'.SnsSettingKey::ModernGroupList->value, 'group');

        $this->assertSame('group', DB::table('sns_settings')->where('key', SnsSettingKey::ModernGroupList->value)->value('value'));
        $this->assertSame(ModernGroupList::Group, app(SnsSettingService::class)->get(SnsSettingKey::ModernGroupList));

        Livewire::test(LandingPageSettings::class)
            ->set('data.'.SnsSettingKey::ModernGroupList->value, 'nope')
            ->call('save')
            ->assertHasErrors('data.'.SnsSettingKey::ModernGroupList->value);
    }

    public function test_a_save_writes_the_three_settings_and_nothing_else(): void
    {
        $before = DB::table('sns_settings')->pluck('key')->all();

        Livewire::test(LandingPageSettings::class)->call('save')->assertHasNoErrors();

        $written = array_values(array_diff(DB::table('sns_settings')->pluck('key')->all(), $before));
        sort($written);
        $this->assertSame([SnsSettingKey::HomeNotice->value, SnsSettingKey::ModernGroupList->value, SnsSettingKey::ModernHome->value], $written);
    }

    public function test_the_notice_round_trips_with_its_leading_indentation(): void
    {
        $body = "    indented code\n\n- item";

        Livewire::test(LandingPageSettings::class)
            ->assertSet('data.'.SnsSettingKey::HomeNotice->value, '')
            ->set('data.'.SnsSettingKey::HomeNotice->value, $body)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame($body, DB::table('sns_settings')->where('key', SnsSettingKey::HomeNotice->value)->value('value'));
        $this->assertSame($body, app(SnsSettingService::class)->get(SnsSettingKey::HomeNotice));
        Livewire::test(LandingPageSettings::class)->assertSet('data.'.SnsSettingKey::HomeNotice->value, $body);
    }

    public function test_a_notice_up_to_the_column_is_saved_and_one_byte_over_is_refused(): void
    {
        Livewire::test(LandingPageSettings::class)
            ->set('data.'.SnsSettingKey::HomeNotice->value, str_repeat('あ', 21845))
            ->call('save')
            ->assertHasNoErrors();
        $this->assertSame(65535, strlen((string) DB::table('sns_settings')->where('key', SnsSettingKey::HomeNotice->value)->value('value')));
        DB::table('sns_settings')->where('key', SnsSettingKey::HomeNotice->value)->delete();

        Livewire::test(LandingPageSettings::class)
            ->set('data.'.SnsSettingKey::HomeNotice->value, str_repeat('あ', 21845).'a')
            ->call('save')
            ->assertHasErrors('data.'.SnsSettingKey::HomeNotice->value);

        $this->assertDatabaseMissing('sns_settings', ['key' => SnsSettingKey::HomeNotice->value]);
    }

    public function test_the_notice_points_at_the_classic_gadget_only_where_classic_is_served(): void
    {
        app()->setLocale('en');

        config(['openpne.surface_mode' => 'classic_default']);
        $this->get('/admin/landing-page-settings')->assertOk()
            ->assertSee('the Information Box gadget under Appearance (Classic) &gt; Gadgets', false);

        config(['openpne.surface_mode' => 'modern_only']);
        $this->get('/admin/landing-page-settings')->assertOk()
            ->assertSee('It is shown at the top of the home.')
            ->assertDontSee('Information Box gadget');
    }

    public function test_the_form_offers_no_layout_to_choose(): void
    {
        Livewire::test(LandingPageSettings::class)
            ->assertFormFieldDoesNotExist('default_look')
            ->assertFormFieldDoesNotExist('selectable_looks');
    }

    /** A migrated site keeps OpenPNE 3's word for a group, so a name built from a site term would not be the one these point at. */
    public function test_the_upgrade_report_and_its_guide_point_at_the_page_by_its_name(): void
    {
        app()->setLocale('en');
        $name = LandingPageSettings::getNavigationLabel();

        $this->assertSame('Landing page settings', $name);
        $this->assertSame(2, substr_count((string) file_get_contents(app_path('Upgrade/Runner/UpgradeRunner.php')), "the {$name} page"));
        $this->assertStringContainsString("the {$name} page", (string) file_get_contents(app_path('Upgrade/Runner/HomeNoticeStamp.php')));
        $this->assertStringContainsString("the {$name} page", (string) file_get_contents(base_path('docs/upgrading-from-openpne3.md')));
    }

    public function test_an_administrator_opens_it_at_the_address_its_name_gives(): void
    {
        $this->assertSame('/admin/landing-page-settings', parse_url(LandingPageSettings::getUrl(), PHP_URL_PATH));

        $this->get('/admin/landing-page-settings')->assertOk()->assertSee('Landing page settings');
        $this->get('/admin/look-settings')->assertNotFound();
    }

    public function test_a_guest_cannot_reach_it(): void
    {
        $this->signOutAdmin();

        $this->get(LandingPageSettings::getUrl())->assertRedirect();
    }
}
