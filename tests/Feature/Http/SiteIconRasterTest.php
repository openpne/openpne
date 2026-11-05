<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Features\Branding\Actions\SaveBrandingSettings;
use App\Files\FileUploader;
use App\Files\GeneratedIcon;
use App\Files\GeneratedIconRaster;
use App\Models\AdminUser;
use App\Models\File;
use App\Models\Member;
use App\Support\FaviconFallback;
use App\Support\SnsSettingKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

/**
 * The raster is what an administrator's browser drew, so the cases are about who may send it, what
 * is accepted, and which version it answers for; the drawing itself is a browser matter.
 */
class SiteIconRasterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('image_cache');
    }

    public function test_the_posted_raster_replaces_the_tile_everywhere_and_changes_the_url(): void
    {
        $tileUrl = app_icon_url(192);
        $this->assertStringNotContainsString('-r', $tileUrl);

        $this->send()->assertNoContent();
        $pngs = $this->pngs();

        foreach (GeneratedIconRaster::SIZES as $size) {
            if ($size === 32) {
                continue;
            }

            $response = $this->get("/site-icon/{$size}.png")->assertOk()->assertHeader('Cache-Control', 'max-age=86400, public');
            $this->assertSame($pngs[$size], $response->getContent(), (string) $size);
        }

        $ico = $this->get('/favicon.ico')->assertOk()->assertHeader('Cache-Control', 'max-age=86400, public')->getContent();
        $this->assertSame($pngs[32], substr($ico, 22));
        // RGB PNG from a browser encoder: the entry declares 24 bits, not the tile's 32.
        $this->assertSame(24, ord($ico[12]));

        $this->assertSame($tileUrl.'-r', app_icon_url(192));
        // Back to the member realm: the admin request above left the admin guard as the default.
        $this->freshRequestState();
        $this->assertStringContainsString('-r', app_icon_url(180));
        $this->get('/login')->assertOk()->assertSee(app_icon_url(180), false);
    }

    public function test_a_too_small_upload_falls_back_to_the_drawn_raster(): void
    {
        $this->send()->assertNoContent();
        $pngs = $this->pngs();
        // Back to the member realm: the admin request above left the admin guard as the default.
        $this->freshRequestState();

        $file = app(FileUploader::class)->store(UploadedFile::fake()->image('icon.png', 32, 32), explicitVisibility: File::VISIBILITY_PUBLIC);
        $this->setSnsSetting(SnsSettingKey::BrandFaviconFile, $file->name);

        $this->assertSame($pngs[512], $this->get(app_icon_url(512))->assertOk()->getContent());
    }

    public function test_only_an_administrator_may_send_one(): void
    {
        $this->send(as: null)->assertNotFound();
        $this->send(as: Member::factory()->create())->assertNotFound();

        Storage::disk('image_cache')->assertDirectoryEmpty('');
        $this->assertFalse(app(GeneratedIcon::class)->hasRaster());
    }

    public function test_a_draw_for_a_name_or_color_since_changed_is_refused(): void
    {
        $this->send(version: 'stale000')->assertStatus(409);

        Storage::disk('image_cache')->assertDirectoryEmpty('');
    }

    public function test_a_wrong_size_a_non_png_or_a_missing_size_writes_nothing(): void
    {
        $this->send(override: [512 => $this->png(511)])->assertStatus(422);
        $this->send(override: [180 => UploadedFile::fake()->image('a.jpg', 180, 180)])->assertStatus(422);
        $this->send(override: [192 => null])->assertStatus(422);

        Storage::disk('image_cache')->assertDirectoryEmpty('');
        $this->assertFalse(app(GeneratedIcon::class)->hasRaster());
    }

    public function test_a_renamed_site_falls_back_to_the_tile_until_the_new_draw_lands(): void
    {
        $this->send()->assertNoContent();
        $old = app(GeneratedIcon::class)->version();

        $this->setSnsSetting(SnsSettingKey::SnsName, 'Renamed');
        $new = app(GeneratedIcon::class)->version();
        $this->assertNotSame($old, $new);

        $this->get('/site-icon/512.png')->assertOk()->assertHeader('Cache-Control', 'max-age=300, public');
        $this->assertStringNotContainsString('-r', app_icon_url(512));

        $this->send()->assertNoContent();
        Storage::disk('image_cache')->assertExists("_site-icon/{$new}/complete");
        Storage::disk('image_cache')->assertMissing("_site-icon/{$old}/complete");
        Storage::disk('image_cache')->assertMissing("_site-icon/{$old}/512.png");

        // A draw for the old name that arrives late is refused, and the new raster is untouched.
        $this->send(version: $old)->assertStatus(409);
        Storage::disk('image_cache')->assertExists("_site-icon/{$new}/complete");
    }

    public function test_a_write_for_an_old_version_that_lands_late_keeps_the_newer_raster(): void
    {
        $this->setSnsSetting(SnsSettingKey::SnsName, 'Alpha');
        $old = app(GeneratedIcon::class)->version();
        $this->setSnsSetting(SnsSettingKey::SnsName, 'Beta');
        $this->send()->assertNoContent();
        $new = app(GeneratedIcon::class)->version();

        // The late request read Alpha before the rename landed in the rows: its memo still says so.
        $this->setSnsSetting(SnsSettingKey::SnsName, 'Alpha');
        $this->assertSame($old, app(GeneratedIcon::class)->version());
        DB::table('sns_settings')->where('key', SnsSettingKey::SnsName->value)->update(['value' => 'Beta']);

        $this->send(version: $old)->assertNoContent();

        Storage::disk('image_cache')->assertExists("_site-icon/{$new}/complete");
        Storage::disk('image_cache')->assertMissing("_site-icon/{$old}/complete");
    }

    public function test_saving_the_branding_page_discards_the_raster_so_it_is_drawn_again(): void
    {
        $this->send()->assertNoContent();
        $this->assertTrue(app(GeneratedIcon::class)->hasRaster());

        app(SaveBrandingSettings::class)('');

        $this->freshRequestState();
        $this->assertFalse(app(GeneratedIcon::class)->hasRaster());
        Storage::disk('image_cache')->assertMissing('_site-icon/'.app(GeneratedIcon::class)->version().'/complete');
    }

    public function test_a_raster_that_cannot_be_discarded_does_not_fail_the_save(): void
    {
        Exceptions::fake();
        $this->mock(GeneratedIconRaster::class, fn ($mock) => $mock->shouldReceive('prune')->once()->andThrow(new RuntimeException('disk')));

        app(SaveBrandingSettings::class)('#112233');

        $this->assertSame('#112233', brand_color());
        Exceptions::assertReported(RuntimeException::class);
    }

    public function test_the_admin_panel_asks_the_browser_to_draw_only_when_it_is_wanted(): void
    {
        $admin = AdminUser::factory()->create();

        // A guest on the login screen: the body-end hook renders there too.
        $this->get('/admin/login')->assertOk()->assertDontSee('data-site-icon-raster', false);

        $page = $this->actingAs($admin, 'admin')->get('/admin')->assertOk();
        $page->assertSee('data-site-icon-raster', false)
            ->assertSee('data-version="'.app(GeneratedIcon::class)->version().'"', false)
            ->assertSee('data-svg-tab=', false)
            ->assertSee('data-svg-tile=', false)
            ->assertSee(route('admin.site_icon.raster'), false);

        $this->setSnsSetting(SnsSettingKey::BrandFaviconFallback, FaviconFallback::None);
        $this->actingAs($admin, 'admin')->get('/admin')->assertOk()->assertSee('data-site-icon-raster', false);

        $this->send(as: $admin)->assertNoContent();
        $this->actingAs($admin, 'admin')->get('/admin')->assertOk()->assertDontSee('data-site-icon-raster', false);

        $file = app(FileUploader::class)->store(UploadedFile::fake()->image('icon.png', 32, 32), explicitVisibility: File::VISIBILITY_PUBLIC);
        $this->setSnsSetting(SnsSettingKey::BrandFaviconFile, $file->name);
        $this->setSnsSetting(SnsSettingKey::SnsName, 'Renamed');
        $this->actingAs($admin, 'admin')->get('/admin')->assertOk()->assertDontSee('data-site-icon-raster', false);
    }

    public function test_a_session_whose_password_changed_is_sent_to_log_in_again(): void
    {
        $admin = AdminUser::factory()->create();

        $this->actingAs($admin, 'admin')
            ->withSession(['password_hash_admin' => 'stale'])
            ->post('/admin/site-icon/raster', ['version' => app(GeneratedIcon::class)->version(), 'icon' => array_map(fn (int $size) => $this->png($size), array_combine(GeneratedIconRaster::SIZES, GeneratedIconRaster::SIZES))])
            ->assertRedirect();

        Storage::disk('image_cache')->assertDirectoryEmpty('');
    }

    public function test_the_existence_memo_lasts_one_request(): void
    {
        $this->send()->assertNoContent();
        $version = app(GeneratedIcon::class)->version();
        $this->assertTrue(app(GeneratedIconRaster::class)->has($version));

        Storage::disk('image_cache')->delete("_site-icon/{$version}/complete");
        $this->assertTrue(app(GeneratedIconRaster::class)->has($version));

        $this->app->forgetScopedInstances();
        $this->assertFalse(app(GeneratedIconRaster::class)->has($version));
    }

    /** @param  array<int, UploadedFile|null>  $override */
    private function send(?string $version = null, array $override = [], mixed $as = 'admin'): TestResponse
    {
        $files = [];
        foreach (GeneratedIconRaster::SIZES as $size) {
            $files[$size] = array_key_exists($size, $override) ? $override[$size] : $this->png($size);
        }

        $actor = $as === 'admin' ? AdminUser::factory()->create() : $as;
        // The admin realm middleware makes `admin` the default guard for the rest of the test, so the member guard is named.
        $request = $actor instanceof AdminUser ? $this->actingAs($actor, 'admin') : ($actor === null ? $this : $this->actingAs($actor, 'member'));

        return $request->post('/admin/site-icon/raster', [
            'version' => $version ?? app(GeneratedIcon::class)->version(),
            'icon' => array_filter($files, fn ($f) => $f !== null),
        ]);
    }

    /** An RGB PNG with a visible mark, as a browser's canvas encoder would write an opaque drawing. */
    private function png(int $size): UploadedFile
    {
        $image = imagecreatetruecolor($size, $size);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 0x25, 0x63, 0xEB));
        imagefilledrectangle($image, 0, 0, intdiv($size, 2), intdiv($size, 2), (int) imagecolorallocate($image, 255, 255, 255));
        ob_start();
        imagepng($image);

        return UploadedFile::fake()->createWithContent("{$size}.png", (string) ob_get_clean());
    }

    /** @return array<int, string> the bytes the last successful post() stored, by size */
    private function pngs(): array
    {
        $version = app(GeneratedIcon::class)->version();
        $pngs = [];
        foreach (GeneratedIconRaster::SIZES as $size) {
            $pngs[$size] = (string) Storage::disk('image_cache')->get("_site-icon/{$version}/{$size}.png");
        }

        return $pngs;
    }
}
