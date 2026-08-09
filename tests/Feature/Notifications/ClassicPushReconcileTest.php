<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesWebPushTransport;
use Tests\TestCase;

/**
 * The Classic header loads the push-ownership reconcile script (public/js/push-reconcile.js) so a
 * shared-browser account switch is closed on Classic too, not only under the Modern shell. This pins
 * the security-relevant gate: the script must never reach a guest page (nobody to rebind ownership to)
 * and must be absent on a site with no push at all. The script's runtime rebind/fail-closed behavior
 * has no browser harness here — see the manual-verification list in the PR.
 */
class ClassicPushReconcileTest extends TestCase
{
    use FakesWebPushTransport;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Render the Blade Classic shell (the phpunit baseline already pins this; kept explicit).
        config(['openpne.surface_mode' => 'classic_default']);
    }

    public function test_a_configured_classic_member_page_loads_the_reconcile_script(): void
    {
        $this->configureVapid();

        $this->actingAs(Member::factory()->create())
            ->get('/member/config?category=notification')
            ->assertOk()
            ->assertSee('js/push-reconcile.js', false);
    }

    public function test_a_guest_classic_page_never_loads_the_reconcile_script(): void
    {
        // VAPID is configured, so the only reason the script is absent is the auth gate — a guest has
        // no subscription of its own to rebind. The login page is a Classic shell a guest actually
        // renders (the csrf-token meta confirms the layout ran), so the partial's gate is exercised,
        // not an auth redirect.
        $this->configureVapid();

        $this->get('/login')
            ->assertOk()
            ->assertSee('name="csrf-token"', false)
            ->assertDontSee('js/push-reconcile.js', false);
    }

    public function test_an_unconfigured_site_omits_the_reconcile_script_for_a_member(): void
    {
        config(['webpush.vapid.public_key' => '', 'webpush.vapid.private_key' => '']);

        $this->actingAs(Member::factory()->create())
            ->get('/member/config?category=notification')
            ->assertOk()
            ->assertDontSee('js/push-reconcile.js', false);
    }
}
