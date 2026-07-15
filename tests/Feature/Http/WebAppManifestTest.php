<?php

namespace Tests\Feature\Http;

use App\Models\Member;
use App\Support\SnsSettingKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebAppManifestTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_manifest_declares_a_standalone_app_scoped_to_the_site_root(): void
    {
        $this->setSnsSetting(SnsSettingKey::SnsName, 'My Community');

        $this->get('/manifest.webmanifest')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/manifest+json')
            ->assertJson([
                'name' => 'My Community',
                'short_name' => 'My Community',
                'display' => 'standalone',
                'scope' => '/',
                'start_url' => '/',
            ])
            ->assertJsonCount(2, 'icons');
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
