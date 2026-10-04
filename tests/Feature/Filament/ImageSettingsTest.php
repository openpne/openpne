<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Pages\ImageSettings;
use App\Models\AdminUser;
use App\Services\SnsSettingService;
use App\Support\SnsSettingKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class ImageSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(AdminUser::factory()->create(), 'admin');
    }

    public function test_it_shows_the_stored_value(): void
    {
        DB::table('sns_settings')->updateOrInsert(['key' => SnsSettingKey::ImageUploadBrowserShrink->value], ['value' => '0']);

        Livewire::test(ImageSettings::class)
            ->assertOk()
            ->assertSet('data.'.SnsSettingKey::ImageUploadBrowserShrink->value, false);
    }

    public function test_it_is_on_before_anyone_saves(): void
    {
        // No row yet: the shipped default is on, and the toggle says so.
        Livewire::test(ImageSettings::class)
            ->assertSet('data.'.SnsSettingKey::ImageUploadBrowserShrink->value, true);
    }

    public function test_saving_stores_the_value_and_clears_the_cache(): void
    {
        // The cache is what every read goes through, so a save that skipped it would leave the site
        // behaving as though nothing changed until the TTL expired.
        $this->assertTrue((bool) app(SnsSettingService::class)->get(SnsSettingKey::ImageUploadBrowserShrink));

        Livewire::test(ImageSettings::class)
            ->set('data.'.SnsSettingKey::ImageUploadBrowserShrink->value, false)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('0', DB::table('sns_settings')->where('key', SnsSettingKey::ImageUploadBrowserShrink->value)->value('value'));
        $this->assertFalse((bool) app(SnsSettingService::class)->get(SnsSettingKey::ImageUploadBrowserShrink));
    }

    public function test_it_can_be_turned_back_on(): void
    {
        DB::table('sns_settings')->updateOrInsert(['key' => SnsSettingKey::ImageUploadBrowserShrink->value], ['value' => '0']);

        Livewire::test(ImageSettings::class)
            ->set('data.'.SnsSettingKey::ImageUploadBrowserShrink->value, true)
            ->call('save');

        $this->assertSame('1', DB::table('sns_settings')->where('key', SnsSettingKey::ImageUploadBrowserShrink->value)->value('value'));
        $this->assertTrue((bool) app(SnsSettingService::class)->get(SnsSettingKey::ImageUploadBrowserShrink));
    }

    public function test_the_copy_names_the_thresholds_as_clamped(): void
    {
        config(['openpne.images.max_upload_kilobytes' => 1024, 'openpne.images.max_upload_dimension' => 1600]);
        app()->setLocale('en');

        $rendered = Livewire::test(ImageSettings::class)->html();

        $this->assertStringContainsString('over 1,600 px on a side, or over 1,024 KB', $rendered);
    }

    public function test_the_page_says_what_turning_it_off_does(): void
    {
        // Asserted through __() under an explicit locale: the panel renders in the site language, so
        // hardcoded English copy would pass or fail on that setting rather than on the warning's presence.
        foreach (['en', 'ja'] as $locale) {
            app()->setLocale($locale);
            $rendered = Livewire::test(ImageSettings::class)->html();
            $this->assertStringContainsString(SnsSettingKey::ImageUploadBrowserShrink->label(), $rendered);
            $this->assertStringContainsString(
                e(__('A large picture (over :px px on a side, or over :kb KB) is shrunk on the member\'s device before it is sent, and its location data does not survive that. Off, the original file is sent and anything over the upload limit is refused.', ['px' => '2,048', 'kb' => '2,048'])),
                $rendered,
            );
        }
    }
}
