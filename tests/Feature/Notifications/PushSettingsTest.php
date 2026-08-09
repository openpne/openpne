<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\Member;
use App\Support\PushDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\FakesWebPushTransport;
use Tests\TestCase;

class PushSettingsTest extends TestCase
{
    use FakesWebPushTransport;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureVapid();
    }

    public function test_the_pause_switch_persists_and_returns_to_the_detail_page(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)->post('/member/config/notifications/push', ['enabled' => false])
            ->assertRedirect(route('member.config.notifications.edit'));
        $this->assertSame(PushDelivery::Disabled, $member->fresh()->pushDelivery());

        $this->actingAs($member)->post('/member/config/notifications/push', ['enabled' => true]);
        $this->assertSame(PushDelivery::Enabled, $member->fresh()->pushDelivery());
    }

    public function test_the_switch_does_not_exist_without_a_vapid_keypair(): void
    {
        config(['webpush.vapid.public_key' => '', 'webpush.vapid.private_key' => '']);

        $this->actingAs(Member::factory()->create())
            ->post('/member/config/notifications/push', ['enabled' => false])
            ->assertNotFound();
    }

    public function test_a_member_is_handed_the_key_their_browser_subscribes_with(): void
    {
        $this->actingAs(Member::factory()->create())
            ->get('/dashboard')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('push.vapidPublicKey', config('webpush.vapid.public_key')));
    }

    public function test_a_guest_is_offered_nothing_to_subscribe_with(): void
    {
        // The phpunit baseline is classic_default; the login page is Inertia only on Modern.
        config()->set('openpne.surface_mode', 'modern_only');

        $this->get('/login')->assertInertia(fn (AssertableInertia $page) => $page->where('push', null));
    }

    public function test_an_unconfigured_site_offers_nothing_to_subscribe_with(): void
    {
        config(['webpush.vapid.public_key' => '', 'webpush.vapid.private_key' => '']);

        $this->actingAs(Member::factory()->create())
            ->get('/dashboard')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('push', null));
    }

    /**
     * Inertia merges page props over the shared ones, so a page prop named `push` would replace the
     * shared VAPID key outright — on the one page that needs both.
     */
    public function test_the_settings_page_carries_both_the_shared_key_and_its_own_toggle_state(): void
    {
        $member = Member::factory()->create();
        $member->setPushDelivery(PushDelivery::Disabled);

        $this->actingAs($member)->get('/member/config/notifications')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('push.vapidPublicKey', config('webpush.vapid.public_key'))
                ->where('pushSettings.enabled', false));
    }
}
