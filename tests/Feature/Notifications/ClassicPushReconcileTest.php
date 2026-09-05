<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesWebPushTransport;
use Tests\TestCase;

/**
 * Pins the gate only: the script must never reach a guest page, and must be absent on a site with no push
 * at all. Its runtime rebind and fail-closed behaviour have no browser harness here.
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

        $member = Member::factory()->create();

        $this->actingAs($member)
            ->get('/member/config?category=notification')
            ->assertOk()
            ->assertSee('js/push-reconcile.js', false)
            ->assertSee('data-push-member-id="'.$member->getKey().'"', false);
    }

    public function test_a_guest_classic_page_never_loads_the_reconcile_script(): void
    {
        // The login page is a Classic shell a guest actually renders, so what is exercised is the
        // partial's gate rather than an auth redirect.
        $this->configureVapid();

        $this->get('/login')
            ->assertOk()
            ->assertSee('name="csrf-token"', false)
            ->assertDontSee('js/push-reconcile.js', false)
            ->assertDontSee('data-push-member-id', false);
    }

    public function test_an_unconfigured_site_omits_the_reconcile_script_for_a_member(): void
    {
        config(['webpush.vapid.public_key' => '', 'webpush.vapid.private_key' => '']);

        $this->actingAs(Member::factory()->create())
            ->get('/member/config?category=notification')
            ->assertOk()
            ->assertDontSee('js/push-reconcile.js', false)
            ->assertDontSee('data-push-member-id', false);
    }
}
