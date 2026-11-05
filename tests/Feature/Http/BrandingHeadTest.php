<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Files\FileUploader;
use App\Files\GeneratedIcon;
use App\Models\File;
use App\Models\Member;
use App\Support\FaviconFallback;
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

    private string $faviconToken = '';

    private function faviconUrl(): string
    {
        $file = app(FileUploader::class)->store(
            UploadedFile::fake()->image('icon.png', 32, 32),
            explicitVisibility: File::VISIBILITY_PUBLIC,
        );
        $this->setSnsSetting(SnsSettingKey::BrandFaviconFile, $file->name);
        $this->faviconToken = $file->name;

        return route('file.public', ['file' => $file->name]);
    }

    private function appIconUrl(int $size): string
    {
        return route('app_icon', ['token' => $this->faviconToken, 'size' => $size]);
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

    public function test_an_unbranded_install_links_the_generated_icon(): void
    {
        $response = $this->modern();

        // The brand lookup runs in a @php block above the doctype; nothing of it may reach the output,
        // or the document leads with whitespace.
        $this->assertStringStartsWith('<!DOCTYPE html>', $response->getContent());

        $response
            ->assertSee('data-theme-color-light="#2563eb"', false)
            ->assertSee('<meta name="theme-color" content="#2563eb">', false)
            ->assertSee('<link rel="icon" href="'.$this->generatedUrl('favicon.ico').'" sizes="32x32">', false)
            ->assertSee('<link rel="icon" href="'.$this->generatedUrl('favicon.svg').'" type="image/svg+xml">', false)
            ->assertSee('<link rel="apple-touch-icon" href="'.$this->generatedUrl('site_icon', 180).'">', false)
            // No inline override, so --primary stays whatever the stylesheet declares per theme.
            ->assertDontSee('--primary:', false);
    }

    public function test_a_site_set_to_no_icon_links_no_tab_icon_on_either_surface(): void
    {
        $this->setSnsSetting(SnsSettingKey::BrandFaviconFallback, FaviconFallback::None);

        // Classic first: modern() logs a member in, and that session would bounce the guest login page.
        foreach ([$this->classic(), $this->modern()] as $response) {
            $response
                ->assertDontSee('rel="icon"', false)
                // The home-screen icon is outside the decision: it stays the brand tile.
                ->assertSee('<link rel="apple-touch-icon" href="'.$this->generatedUrl('site_icon', 180).'">', false)
                ->assertSee(route('webmanifest'), false);
        }
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
            ->assertDontSee('favicon.svg', false)
            ->assertDontSee('/favicon.ico', false)
            // The home-screen icon is derived from the same upload, not the shipped asset.
            ->assertSee('<link rel="apple-touch-icon" href="'.$this->appIconUrl(180).'">', false);
    }

    public function test_the_uploaded_favicon_replaces_the_shipped_icons_on_classic(): void
    {
        $url = $this->faviconUrl();

        $this->classic()
            ->assertSee('<link rel="icon" type="image/png" href="'.$url.'">', false)
            ->assertDontSee('favicon.svg', false)
            ->assertSee('<link rel="apple-touch-icon" href="'.$this->appIconUrl(180).'">', false);
    }

    public function test_classic_links_the_generated_icon_when_no_favicon_is_set(): void
    {
        $this->classic()
            ->assertSee('<link rel="icon" href="'.$this->generatedUrl('favicon.svg').'" type="image/svg+xml">', false)
            ->assertSee('<link rel="apple-touch-icon" href="'.$this->generatedUrl('site_icon', 180).'">', false)
            // The brand color is Modern-only; Classic is styled by its skin.
            ->assertDontSee('--primary:', false);
    }

    private function generatedUrl(string $route, ?int $size = null): string
    {
        $parameters = ['v' => app(GeneratedIcon::class)->version()];

        return route($route, $size === null ? $parameters : ['size' => $size] + $parameters);
    }
}
