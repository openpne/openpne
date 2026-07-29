<?php

declare(strict_types=1);

namespace Tests\Feature\Classic;

use App\Models\AdminUser;
use App\Models\Member;
use App\Support\SnsSettingKey;
use App\Support\Surface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * OpenPNE 3's `default/error` screen: 403/404/419 render inside the Classic shell. The framework's
 * own error pages stay in place for everyone else — JSON clients, the admin panel, Modern.
 *
 * The abort() probe routes stand in for the real sources (a policy denial, a stale CSRF token): the
 * exception handler sees the same HttpException either way, and a probe keeps each status reachable
 * from one place. `PreventRequestForgery` skips validation under the test runner, so a real 419
 * cannot be provoked at all.
 */
class ClassicErrorPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setSnsSetting(SnsSettingKey::SurfaceMode, 'classic_default');
    }

    private function probeRoute(int $status): string
    {
        Route::middleware('web')->get("/__error_probe/{$status}", fn () => abort($status));

        return "/__error_probe/{$status}";
    }

    public function test_a_not_found_on_a_matched_route_renders_inside_the_shell(): void
    {
        $member = Member::factory()->create();

        $response = $this->actingAs($member)->get('/diary/999999')->assertNotFound();

        // The OpenPNE 3 screen: the body id hook, the message as bare text (no parts box around
        // it), and the history-back line.
        $response->assertSee('id="page_default_error"', false);
        $response->assertSee("You can't access this page.");
        $response->assertSee('<div class="parts line" id="backLink">', false);
        $response->assertSee('onclick="history.back(); return false;"', false);
        $this->assertDoesNotMatchRegularExpression(
            '/class="[^"]*alertBox[^"]*"/',
            $response->getContent(),
            "OpenPNE 3's error screen prints the message bare, not in an alertBox.",
        );

        // It is the site's own shell, not a standalone page.
        $response->assertSee('id="globalNav"', false);
    }

    public function test_the_failing_routes_module_lends_the_error_page_no_stylesheet(): void
    {
        // /diary/* resolves to opDiaryPlugin's stylesheet, but OpenPNE 3's default module declares
        // none — the error screen is not the diary screen, only the URL that failed was.
        $member = Member::factory()->create();

        $this->actingAs($member)->get('/diary/999999')
            ->assertNotFound()
            ->assertDontSee('diary.css');
    }

    public function test_forbidden_and_expired_render_the_same_screen_with_their_own_status(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)->get($this->probeRoute(403))
            ->assertForbidden()
            ->assertSee('id="page_default_error"', false)
            ->assertSee("You can't access this page.")
            // The shell an error carries is the whole shell, header included (NotificationCenterTest).
            ->assertSee('id="notificationCenter"', false);

        $this->actingAs($member)->get($this->probeRoute(419))
            ->assertStatus(419)
            ->assertSee('id="page_default_error"', false)
            ->assertSee('CSRF attack detected.');
    }

    public function test_a_guest_gets_the_insecure_page_body_class(): void
    {
        $this->get($this->probeRoute(404))
            ->assertNotFound()
            ->assertSee('class="insecure_page"', false);
    }

    public function test_the_modern_client_and_the_admin_realm_keep_the_framework_page(): void
    {
        $member = Member::factory()->create();

        // An Inertia navigation cannot consume Classic Blade. POST, because Inertia answers a GET
        // whose asset version does not match with a 409 of its own before the status gets here.
        Route::middleware('web')->post('/__error_probe_inertia', fn () => abort(419));
        $this->actingAs($member)->post('/__error_probe_inertia', [], ['X-Inertia' => 'true'])
            ->assertStatus(419)
            ->assertDontSee('page_default_error');

        Route::middleware('web')->get('/admin/__error_probe', fn () => abort(419));
        $this->freshRequestState();
        $this->actingAs(AdminUser::factory()->create(), 'admin')->get('/admin/__error_probe')
            ->assertStatus(419)
            ->assertDontSee('page_default_error');
    }

    public function test_a_modern_member_and_a_modern_only_install_keep_the_framework_page(): void
    {
        $member = Member::factory()->create();
        $member->setPreferredSurface(Surface::Modern);

        $this->actingAs($member)->get('/diary/999999')
            ->assertNotFound()
            ->assertDontSee('page_default_error');

        $this->setSnsSetting(SnsSettingKey::SurfaceMode, 'modern_only');
        $classicMember = Member::factory()->create();
        $classicMember->setPreferredSurface(Surface::Classic);

        $this->actingAs($classicMember)->get('/diary/999999')
            ->assertNotFound()
            ->assertDontSee('page_default_error');
    }

    public function test_an_unmatched_url_is_a_full_request_not_a_router_level_404(): void
    {
        $member = Member::factory()->create();

        $response = $this->actingAs($member)->get('/no-such-page');

        // Routed through the web group: the member is still signed in (so the shell is the
        // signed-in one) and the response carries the baseline headers.
        $response->assertNotFound()
            ->assertSee('id="page_default_error"', false)
            ->assertSee('class="secure_page"', false)
            ->assertSee('id="globalNav"', false)
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertAuthenticated('member');
    }

    public function test_an_unmatched_url_in_the_admin_realm_keeps_the_framework_page(): void
    {
        $this->actingAs(AdminUser::factory()->create(), 'admin')->get('/admin/no-such-page')
            ->assertNotFound()
            ->assertDontSee('page_default_error');
    }

    public function test_the_error_page_speaks_the_members_locale_on_an_implicit_binding_404(): void
    {
        // The binding aborts inside the group, before the route action — SetLocale has to be
        // upstream of it or the page renders in APP_LOCALE whatever the member chose.
        $member = Member::factory()->create(['locale' => 'ja']);

        $this->actingAs($member)->get('/diary/deleteConfirm/999999')
            ->assertNotFound()
            ->assertSee('このページにはアクセスできません。')
            ->assertSee('前のページに戻る');
    }

    public function test_a_json_client_keeps_json(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)->getJson('/diary/999999')->assertNotFound()->assertJsonStructure(['message']);
        $this->freshRequestState();
        $this->getJson('/no-such-page')->assertNotFound()->assertJsonStructure(['message']);
        $this->freshRequestState();
        $this->getJson('/dashboard')->assertUnauthorized();
    }

    public function test_a_system_route_outside_the_web_group_keeps_its_native_error(): void
    {
        // The framework's private-file server (storage.local) runs without the web group — no
        // session, no locale, no shell dependencies — so its errors must stay native.
        $this->get('/storage/definitely-missing')
            ->assertForbidden()
            ->assertDontSee('page_default_error');
    }
}
