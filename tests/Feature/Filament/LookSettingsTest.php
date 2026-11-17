<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Pages\LookSettings;
use App\Models\AdminUser;
use App\Services\SnsSettingService;
use App\Support\ModernGroupList;
use App\Support\ModernHome;
use App\Support\SnsSettingKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class LookSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(AdminUser::factory()->create(), 'admin');
    }

    public function test_the_home_page_is_the_issue_until_saved_and_round_trips(): void
    {
        Livewire::test(LookSettings::class)
            ->assertSet('data.'.SnsSettingKey::ModernHome->value, 'issue')
            ->set('data.'.SnsSettingKey::ModernHome->value, 'whats_new')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('data.'.SnsSettingKey::ModernHome->value, 'whats_new');

        $this->assertSame('whats_new', DB::table('sns_settings')->where('key', SnsSettingKey::ModernHome->value)->value('value'));
        $this->assertSame(ModernHome::WhatsNew, app(SnsSettingService::class)->get(SnsSettingKey::ModernHome));

        Livewire::test(LookSettings::class)
            ->set('data.'.SnsSettingKey::ModernHome->value, 'nope')
            ->call('save')
            ->assertHasErrors('data.'.SnsSettingKey::ModernHome->value);
    }

    public function test_a_joined_group_row_opens_the_talk_until_saved_and_round_trips(): void
    {
        Livewire::test(LookSettings::class)
            ->assertSet('data.'.SnsSettingKey::ModernGroupList->value, 'talk')
            ->set('data.'.SnsSettingKey::ModernGroupList->value, 'group')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('data.'.SnsSettingKey::ModernGroupList->value, 'group');

        $this->assertSame('group', DB::table('sns_settings')->where('key', SnsSettingKey::ModernGroupList->value)->value('value'));
        $this->assertSame(ModernGroupList::Group, app(SnsSettingService::class)->get(SnsSettingKey::ModernGroupList));

        Livewire::test(LookSettings::class)
            ->set('data.'.SnsSettingKey::ModernGroupList->value, 'nope')
            ->call('save')
            ->assertHasErrors('data.'.SnsSettingKey::ModernGroupList->value);
    }

    public function test_a_save_writes_the_two_settings_and_nothing_else(): void
    {
        $before = DB::table('sns_settings')->pluck('key')->all();

        Livewire::test(LookSettings::class)->call('save')->assertHasNoErrors();

        $written = array_values(array_diff(DB::table('sns_settings')->pluck('key')->all(), $before));
        sort($written);
        $this->assertSame([SnsSettingKey::ModernGroupList->value, SnsSettingKey::ModernHome->value], $written);
    }

    public function test_the_form_offers_no_layout_to_choose(): void
    {
        Livewire::test(LookSettings::class)
            ->assertFormFieldDoesNotExist('default_look')
            ->assertFormFieldDoesNotExist('selectable_looks');
    }

    /** A migrated site keeps OpenPNE 3's word for a group, so a name built from a site term would not be the one these point at. */
    public function test_the_upgrade_report_and_its_guide_point_at_the_page_by_its_name(): void
    {
        app()->setLocale('en');
        $name = LookSettings::getNavigationLabel();

        $this->assertSame('Landing page settings', $name);
        $this->assertSame(2, substr_count((string) file_get_contents(app_path('Upgrade/Runner/UpgradeRunner.php')), "the {$name} page"));
        $this->assertStringContainsString("the {$name} page", (string) file_get_contents(base_path('docs/upgrading-from-openpne3.md')));
    }

    public function test_a_guest_cannot_reach_it(): void
    {
        auth('admin')->logout();

        $this->get(LookSettings::getUrl())->assertRedirect();
    }
}
