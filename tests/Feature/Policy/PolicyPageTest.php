<?php

declare(strict_types=1);

namespace Tests\Feature\Policy;

use App\Models\Member;
use App\Support\SnsSettingKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The terms of service / privacy policy pages. Public by design — someone deciding whether to join
 * has to be able to read them — and reachable on both surfaces, with the OpenPNE 3 URLs preserved.
 */
class PolicyPageTest extends TestCase
{
    use RefreshDatabase;

    private function classic(): void
    {
        $this->setSnsSetting(SnsSettingKey::SurfaceMode, 'classic_default');
    }

    private function modern(): void
    {
        $this->setSnsSetting(SnsSettingKey::SurfaceMode, 'modern_only');
    }

    public function test_a_guest_reads_the_policies_on_classic(): void
    {
        $this->classic();
        $this->setSnsSetting(SnsSettingKey::UserAgreement, "## 第1条\n本規約は…");
        $this->setSnsSetting(SnsSettingKey::PrivacyPolicy, '取得する情報について');

        $this->get('/terms')
            ->assertOk()
            ->assertSee('<h2>第1条</h2>', false)
            ->assertSee('id="userAgreement"', false)
            // OpenPNE 3 served these from a module with is_secure: false, so the page is the
            // pre-login one whoever is reading it.
            ->assertSee('class="insecure_page"', false);

        $this->get('/privacy')->assertOk()->assertSee('取得する情報について');
    }

    public function test_a_guest_reads_the_policies_on_modern(): void
    {
        $this->modern();
        $this->setSnsSetting(SnsSettingKey::UserAgreement, '## 第1条');

        $this->get('/terms')->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page->component('policy/show')
                ->where('kind', 'terms')
                ->where('bodyHtml', "<h2>第1条</h2>\n"),
        );

        $this->get('/privacy')->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page->component('policy/show')->where('kind', 'privacy'),
        );
    }

    public function test_a_member_reads_the_same_pages(): void
    {
        $this->classic();
        $this->setSnsSetting(SnsSettingKey::UserAgreement, '会員規約');

        $this->actingAs(Member::factory()->create(), 'member')
            ->get('/terms')
            ->assertOk()
            ->assertSee('会員規約');
    }

    public function test_the_openpne3_urls_redirect_permanently(): void
    {
        $this->get('/userAgreement')->assertRedirect('/terms')->assertStatus(301);
        $this->get('/default/userAgreement')->assertRedirect('/terms')->assertStatus(301);
        $this->get('/privacyPolicy')->assertRedirect('/privacy')->assertStatus(301);
        $this->get('/default/privacyPolicy')->assertRedirect('/privacy')->assertStatus(301);
    }

    public function test_an_unwritten_policy_says_so_instead_of_404ing(): void
    {
        $this->classic();

        $this->get('/terms')->assertOk()->assertSee(__('This page is not written yet.'));

        $this->modern();
        $this->get('/terms')->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page->where('body', null)->where('bodyHtml', null),
        );
    }

    public function test_operator_markup_is_sanitized(): void
    {
        // Admin-authored, but it takes the member-body sanitizer rather than the operator-HTML path
        // the Classic design slots use.
        $this->classic();
        $this->setSnsSetting(SnsSettingKey::UserAgreement, "本文\n<script>alert(1)</script>");

        $this->get('/terms')->assertOk()->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_the_classic_footer_links_to_both_pages(): void
    {
        $this->classic();

        $response = $this->get('/terms')->assertOk();

        $response->assertSee('href="'.route('policy.privacy').'"', false);
        $response->assertSee('href="'.route('policy.terms').'"', false);
    }
}
