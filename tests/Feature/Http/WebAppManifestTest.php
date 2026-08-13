<?php

namespace Tests\Feature\Http;

use App\Files\FileUploader;
use App\Models\File;
use App\Models\Member;
use App\Support\SnsSettingKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class WebAppManifestTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_manifest_declares_a_standalone_app_scoped_to_the_site_root(): void
    {
        $this->setSnsSetting(SnsSettingKey::SnsName, 'My Group');

        $this->get('/manifest.webmanifest')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/manifest+json')
            ->assertJson([
                'name' => 'My Group',
                'short_name' => 'My Group',
                'display' => 'standalone',
                'scope' => '/',
                'start_url' => '/',
            ])
            ->assertJsonCount(2, 'icons')
            ->assertJson(['theme_color' => '#2563eb'])
            ->assertJson(['icons' => [['src' => asset('icon-192x192.png')], ['src' => asset('icon-512x512.png')]]]);
    }

    public function test_the_manifest_icons_follow_the_branding_favicon(): void
    {
        $file = app(FileUploader::class)->store(
            UploadedFile::fake()->image('icon.png', 512, 512),
            explicitVisibility: File::VISIBILITY_PUBLIC,
        );
        $this->setSnsSetting(SnsSettingKey::BrandFaviconFile, $file->name);

        $this->get('/manifest.webmanifest')
            ->assertOk()
            ->assertJson(['icons' => [
                ['src' => route('app_icon', ['token' => $file->name, 'size' => 192])],
                ['src' => route('app_icon', ['token' => $file->name, 'size' => 512])],
            ]]);
    }

    public function test_the_manifest_theme_color_follows_the_brand_color(): void
    {
        $this->setSnsSetting(SnsSettingKey::BrandColor, '#0088aa');

        $this->get('/manifest.webmanifest')
            ->assertOk()
            ->assertJson(['theme_color' => '#0088aa']);
    }

    public function test_both_shells_link_the_manifest(): void
    {
        // Classic shell (guest-reachable login page) and the Modern Inertia root view.
        $this->get('/login')
            ->assertOk()
            ->assertSee('rel="manifest"', false)
            ->assertSee(route('webmanifest'), false);

        $this->actingAs(Member::factory()->create())
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('rel="manifest"', false)
            ->assertSee(route('webmanifest'), false);
    }
}
