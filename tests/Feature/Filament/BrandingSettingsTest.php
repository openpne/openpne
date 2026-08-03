<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Pages\BrandingSettings;
use App\Files\FileUploader;
use App\Models\AdminUser;
use App\Models\File;
use App\Services\SnsSettingService;
use App\Support\SnsSettingKey;
use App\Support\SurfaceMode;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The branding editor. The upload fields cannot be driven from a Livewire test (no real temp upload),
 * so the file behaviour lives in Tests\Feature\Branding\SaveBrandingSettingsTest; what is asserted
 * here is the color round-trip and that the page exposes only its own state.
 */
class BrandingSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Start from a clean settings table: the base TestCase seeds the Auth-group baseline, which
        // this page must never touch.
        DB::table('sns_settings')->truncate();
        app(SnsSettingService::class)->clearCache();

        Filament::setCurrentPanel('admin');
        $this->actingAs(AdminUser::factory()->create(), 'admin');
    }

    public function test_saving_stores_the_brand_color_verbatim(): void
    {
        Livewire::test(BrandingSettings::class)
            ->fillForm(['brand_color' => '#0088AA'])
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('data.brand_color', '#0088AA');

        $this->assertDatabaseHas('sns_settings', ['key' => 'brand_color', 'value' => '#0088AA']);
    }

    public function test_a_blank_color_is_stored_as_unbranded(): void
    {
        Livewire::test(BrandingSettings::class)
            ->fillForm(['brand_color' => ''])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('sns_settings', ['key' => 'brand_color', 'value' => '']);
        $this->assertNull(brand_color());
    }

    public function test_a_malformed_color_is_rejected(): void
    {
        Livewire::test(BrandingSettings::class)
            ->fillForm(['brand_color' => 'cornflower'])
            ->call('save')
            ->assertHasErrors('data.brand_color');

        $this->assertDatabaseMissing('sns_settings', ['key' => 'brand_color']);
    }

    public function test_saving_writes_every_branding_key(): void
    {
        Livewire::test(BrandingSettings::class)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('sns_settings', ['key' => 'brand_color', 'value' => '']);
        $this->assertDatabaseHas('sns_settings', ['key' => 'brand_logo_file', 'value' => '']);
        $this->assertDatabaseHas('sns_settings', ['key' => 'brand_favicon_file', 'value' => '']);
        $this->assertSame(3, DB::table('sns_settings')->count());
    }

    public function test_the_admin_panel_favicon_follows_the_uploaded_one(): void
    {
        $this->assertSame(asset('favicon-32x32.png'), Filament::getCurrentPanel()->getFavicon());

        $file = app(FileUploader::class)->store(
            UploadedFile::fake()->image('icon.png', 32, 32),
            explicitVisibility: File::VISIBILITY_PUBLIC,
        );
        $this->setSnsSetting(SnsSettingKey::BrandFaviconFile, $file->name);

        $this->assertSame(route('file.public', ['file' => $file->name]), Filament::getCurrentPanel()->getFavicon());
    }

    public function test_the_page_exposes_only_its_own_state(): void
    {
        // Drift guard: the file fields and the removal toggles are the page's own state, not settings
        // keys, so this list is spelled out rather than derived from the Branding group.
        $actual = array_keys(Livewire::test(BrandingSettings::class)->get('data'));
        sort($actual);

        $expected = [
            SnsSettingKey::BrandColor->value,
            SnsSettingKey::BrandFaviconFile->value,
            SnsSettingKey::BrandLogoFile->value,
            'remove_brand_favicon',
            'remove_brand_logo',
        ];
        sort($expected);

        $this->assertSame($expected, $actual);
    }

    public function test_the_copy_labels_the_surface_scope_while_classic_is_available(): void
    {
        $this->setSnsSetting(SnsSettingKey::SurfaceMode, SurfaceMode::ClassicDefault);

        Livewire::test(BrandingSettings::class)
            ->assertSee('モダンの会員画面')
            ->assertSee('クラシックは文字ロゴのままです')
            ->assertSee('クラシック・モダン両方');
    }

    public function test_the_copy_never_mentions_surfaces_under_modern_only(): void
    {
        // The modern_only invariant: nothing the operator sees may acknowledge Classic exists —
        // including the scope labels this page carries in the mixed modes.
        $this->setSnsSetting(SnsSettingKey::SurfaceMode, SurfaceMode::ModernOnly);

        Livewire::test(BrandingSettings::class)
            ->assertSee('会員画面')
            ->assertDontSee('クラシック')
            ->assertDontSee('モダン');
    }
}
