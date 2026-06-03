<?php

declare(strict_types=1);

namespace Tests\Feature\Classic;

use App\Models\Member;
use App\Models\MemberProfile;
use App\Models\Profile;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ShellNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_secure_page_renders_the_default_local_nav(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)->get('/')
            ->assertOk()
            ->assertSee('<ul class="default">', false)
            ->assertSee('id="default_home"', false)
            ->assertSee('id="default_friend"', false)
            ->assertSee('id="default_diary"', false)
            ->assertSee('id="default_profile"', false)
            ->assertSee('id="default_editProfile"', false);
    }

    public function test_guest_on_a_classic_page_does_not_see_the_local_nav(): void
    {
        // localNav is secure-only in OpenPNE 3; a web-public profile reaches the
        // Classic shell as a guest, so the hook is present but unpopulated.
        $owner = Member::factory()->create(['profile_visibility' => Visibility::Open]);
        $profile = Profile::factory()->create(['is_public_web' => true]);
        MemberProfile::factory()->create([
            'member_id' => $owner->getKey(), 'profile_id' => $profile->getKey(),
            'value' => 'public-value', 'visibility' => Visibility::Open,
        ]);

        $this->get("/member/{$owner->getKey()}")
            ->assertOk()
            ->assertSee('id="localNav"', false)
            ->assertDontSee('<ul class="default">', false);
    }

    public function test_footer_renders_the_configured_html(): void
    {
        config(['openpne.classic.footer_html' => 'Operated by <a href="https://example.test">Example</a>']);
        $member = Member::factory()->create();

        $this->actingAs($member)->get('/')
            ->assertOk()
            ->assertSee('Operated by <a href="https://example.test">Example</a>', false);
    }

    public function test_footer_paragraph_is_omitted_when_no_html_is_configured(): void
    {
        config(['openpne.classic.footer_html' => '']);
        $member = Member::factory()->create();

        $html = $this->actingAs($member)->get('/')->assertOk()->getContent();
        $footer = Str::between($html, '<div id="FooterContainer">', '</div>');

        $this->assertStringNotContainsString('<p>', $footer);
    }
}
