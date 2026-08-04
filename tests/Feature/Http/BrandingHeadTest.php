<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Files\FileUploader;
use App\Models\File;
use App\Models\Member;
use App\Support\SnsSettingKey;
use App\Support\SurfaceMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * What the per-site branding puts in each shell's <head>. The surface mode is set per case so the
 * Modern root view and the Classic layout are each definitely the one that rendered: the brand color
 * and logo are Modern-only, the favicon applies to both.
 */
class BrandingHeadTest extends TestCase
{
    use RefreshDatabase;

    private function faviconUrl(): string
    {
        $file = app(FileUploader::class)->store(
            UploadedFile::fake()->image('icon.png', 32, 32),
            explicitVisibility: File::VISIBILITY_PUBLIC,
        );
        $this->setSnsSetting(SnsSettingKey::BrandFaviconFile, $file->name);

        return route('file.public', ['file' => $file->name]);
    }

    private function modern(): TestResponse
    {
        $this->setSnsSetting(SnsSettingKey::SurfaceMode, SurfaceMode::ModernOnly);

        return $this->actingAs(Member::factory()->create())->get('/dashboard')->assertOk();
    }

    private function classic(): TestResponse
    {
        $this->setSnsSetting(SnsSettingKey::SurfaceMode, SurfaceMode::ClassicDefault);

        return $this->get('/login')->assertOk();
    }

    public function test_an_unbranded_install_keeps_the_built_in_head(): void
    {
        $response = $this->modern();

        // The brand lookup runs in a @php block above the doctype; nothing of it may reach the output,
        // or the document leads with whitespace.
        $this->assertStringStartsWith('<!DOCTYPE html>', $response->getContent());

        $response
            ->assertSee('data-theme-color-light="#2563eb"', false)
            ->assertSee('<meta name="theme-color" content="#2563eb">', false)
            ->assertSee(asset('favicon-32x32.png'), false)
            ->assertSee(asset('apple-touch-icon.png'), false)
            // No inline override, so --primary stays whatever the stylesheet declares per theme.
            ->assertDontSee('--primary:', false);
    }

    public function test_a_brand_color_overrides_only_the_primary_variables(): void
    {
        $this->setSnsSetting(SnsSettingKey::BrandColor, '#0088aa');

        $this->modern()
            ->assertSee('style="--primary: #0088aa; --primary-foreground: #000000"', false)
            ->assertSee('data-theme-color-light="#0088aa"', false)
            ->assertSee('<meta name="theme-color" content="#0088aa">', false)
            ->assertDontSee('--selected:', false)
            ->assertDontSee('--ring:', false);
    }

    public function test_a_corrupt_brand_color_never_reaches_the_style_attribute(): void
    {
        $this->setSnsSetting(SnsSettingKey::BrandColor, 'red; background: url(javascript:alert(1))');

        $this->modern()
            ->assertSee('data-theme-color-light="#2563eb"', false)
            ->assertDontSee('--primary:', false)
            ->assertDontSee('javascript:', false);
    }

    public function test_the_uploaded_favicon_replaces_the_shipped_icons_on_modern(): void
    {
        $url = $this->faviconUrl();

        $this->modern()
            ->assertSee('<link rel="icon" type="image/png" href="'.$url.'">', false)
            ->assertDontSee('favicon-32x32.png', false)
            ->assertDontSee('/favicon.ico', false)
            // The home-screen icon is derived from the same upload, not the shipped asset.
            ->assertSee('<link rel="apple-touch-icon" href="'.route('app_icon', ['size' => 180]).'">', false);
    }

    public function test_the_uploaded_favicon_replaces_the_shipped_icons_on_classic(): void
    {
        $url = $this->faviconUrl();

        $this->classic()
            ->assertSee('<link rel="icon" type="image/png" href="'.$url.'">', false)
            ->assertDontSee('favicon-32x32.png', false)
            ->assertSee('<link rel="apple-touch-icon" href="'.route('app_icon', ['size' => 180]).'">', false);
    }

    public function test_classic_keeps_the_shipped_icons_when_no_favicon_is_set(): void
    {
        $this->classic()
            ->assertSee(asset('favicon-32x32.png'), false)
            ->assertSee(asset('apple-touch-icon.png'), false)
            // The brand color is Modern-only; Classic is styled by its skin.
            ->assertDontSee('--primary:', false);
    }
}
