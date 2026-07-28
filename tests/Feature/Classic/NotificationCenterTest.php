<?php

declare(strict_types=1);

namespace Tests\Feature\Classic;

use App\Models\Member;
use App\Models\MemberProfile;
use App\Models\Message;
use App\Models\MessageRecipient;
use App\Models\Profile;
use App\Support\SnsSettingKey;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * OpenPNE 3's `#notificationCenter`: the header sprite and its three badges. Who gets it is the
 * load-bearing part — the Classic shell also renders for a guest and for an error page, where
 * there is no member to count for.
 */
class NotificationCenterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setSnsSetting(SnsSettingKey::SurfaceMode, 'classic_default');
    }

    public function test_a_signed_in_member_gets_the_sprite_and_its_image_map(): void
    {
        $response = $this->actingAs(Member::factory()->create())->get('/')->assertOk();

        $response->assertSee('id="notificationCenter"', false);
        $response->assertSee('<img class="ncbutton" src="'.e(asset('images/NOTIFY_CENTER.png')).'" width="92" height="32" alt="" usemap="#notificationCenterMap">', false);
        // The sprite's three cells, wall to wall: an area narrowed to the glyph inside it would
        // leave the gaps between icons dead.
        $response->assertSee('<area shape="rect" coords="0,0,30,32" href="'.e(route('message.index')).'"', false);
        $response->assertSee('<area shape="rect" coords="31,0,61,32" href="'.e(route('friend.manage')).'"', false);
        $response->assertSee('<area shape="rect" coords="62,0,92,32" href="'.e(route('notifications.index')).'"', false);
    }

    /**
     * The skin drops each badge over the icon it counts and lets a wide one hang past the sprite,
     * so a badge that were not a target itself would both swallow the clicks it covers and waste
     * the ones it overhangs. Every badge leads where its icon leads.
     */
    public function test_a_badge_leads_where_the_icon_it_sits_on_leads(): void
    {
        $viewer = Member::factory()->create();
        $sender = Member::factory()->create();
        DB::table('friend_requests')->insert(['requester_id' => $sender->getKey(), 'target_id' => $viewer->getKey()]);

        $content = (string) $this->actingAs($viewer)->get('/')->assertOk()->getContent();

        $this->assertStringContainsString(
            '<a href="'.e(route('friend.manage')).'" id="nc_icon2" class="notificationCenterBadge" aria-hidden="true" tabindex="-1">1</a>',
            $content,
        );
    }

    /**
     * The count rides on the area: that is what a screen reader and a hover reach, and the badge
     * showing the digits repeats the destination without repeating the announcement.
     */
    public function test_the_count_is_announced_on_the_link_rather_than_on_the_badge(): void
    {
        $viewer = Member::factory()->create();
        $sender = Member::factory()->create();
        DB::table('friend_requests')->insert(['requester_id' => $sender->getKey(), 'target_id' => $viewer->getKey()]);

        $content = (string) $this->actingAs($viewer)->get('/')->assertOk()->getContent();

        $name = e(__(':count pending %friend% requests', ['count' => 1]));
        $this->assertStringContainsString('href="'.e(route('friend.manage')).'" alt="'.$name.'" title="'.$name.'"', $content);
        // The badge repeats the area rather than joining it: hidden and out of the tab order, so
        // the count is announced once and the keyboard still sees three links.
        $this->assertStringContainsString('id="nc_icon2" class="notificationCenterBadge" aria-hidden="true" tabindex="-1">1</a>', $content);
    }

    public function test_an_icon_with_nothing_waiting_is_named_for_where_it_leads(): void
    {
        $content = (string) $this->actingAs(Member::factory()->create())->get('/')->assertOk()->getContent();

        $name = e(__('Pending %friend% requests'));
        $this->assertStringContainsString('href="'.e(route('friend.manage')).'" alt="'.$name.'" title="'.$name.'"', $content);
    }

    public function test_a_badge_is_absent_while_its_count_is_zero(): void
    {
        $this->actingAs(Member::factory()->create())->get('/')
            ->assertOk()
            ->assertSee('id="notificationCenter"', false)
            ->assertDontSee('id="nc_icon1"', false)
            ->assertDontSee('id="nc_icon2"', false)
            ->assertDontSee('id="nc_icon3"', false);
    }

    public function test_each_badge_reports_its_own_count(): void
    {
        $viewer = Member::factory()->create();
        $sender = Member::factory()->create();
        DB::table('friend_requests')->insert(['requester_id' => $sender->getKey(), 'target_id' => $viewer->getKey()]);
        $message = Message::factory()->create(['sender_id' => $sender->getKey()]);
        MessageRecipient::factory()->create(['message_id' => $message->getKey(), 'recipient_id' => $viewer->getKey()]);
        $viewer->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => 'test',
            'data' => ['kind' => 'friend_requested', 'requester_id' => $sender->getKey()],
        ]);

        $content = (string) $this->actingAs($viewer)->get('/')->assertOk()->getContent();

        foreach (['nc_icon1', 'nc_icon2', 'nc_icon3'] as $id) {
            $this->assertMatchesRegularExpression('/<a [^>]*id="'.$id.'"[^>]*>1<\/a>/', $content);
        }
    }

    public function test_a_count_past_the_badges_width_is_clamped_but_still_readable(): void
    {
        $viewer = Member::factory()->create();
        foreach (Member::factory()->count(120)->create() as $sender) {
            DB::table('friend_requests')->insert(['requester_id' => $sender->getKey(), 'target_id' => $viewer->getKey()]);
        }

        $content = (string) $this->actingAs($viewer)->get('/')->assertOk()->getContent();

        // The skin sizes the badge for OpenPNE 3's capped count, so the digits stop — but the
        // number a member acts on stays on the link.
        $name = e(__(':count pending %friend% requests', ['count' => 120]));
        $this->assertStringContainsString('id="nc_icon2" class="notificationCenterBadge" aria-hidden="true" tabindex="-1">99+</a>', $content);
        $this->assertStringContainsString('alt="'.$name.'" title="'.$name.'"', $content);
    }

    public function test_a_guest_on_a_web_public_page_gets_no_notification_center(): void
    {
        $owner = Member::factory()->create(['profile_visibility' => Visibility::Open]);
        $profile = Profile::factory()->create(['is_public_web' => true]);
        MemberProfile::factory()->create([
            'member_id' => $owner->getKey(), 'profile_id' => $profile->getKey(),
            'value' => 'public-value', 'visibility' => Visibility::Open,
        ]);

        $this->get("/member/{$owner->getKey()}")
            ->assertOk()
            ->assertSee('id="globalNav"', false)
            ->assertDontSee('id="notificationCenter"', false);
    }

    public function test_a_signed_in_member_keeps_the_notification_center_on_an_error_page(): void
    {
        $viewer = Member::factory()->create();
        $sender = Member::factory()->create();
        $message = Message::factory()->create(['sender_id' => $sender->getKey()]);
        MessageRecipient::factory()->create(['message_id' => $message->getKey(), 'recipient_id' => $viewer->getKey()]);

        // The Classic error screen is the site's own shell (see ClassicErrorPageTest), so the
        // header it carries is the whole header.
        $this->actingAs($viewer)->get('/diary/999999')
            ->assertNotFound()
            ->assertSee('id="notificationCenter"', false)
            ->assertSee('id="nc_icon1"', false);
    }

    public function test_a_guest_error_page_has_no_notification_center(): void
    {
        $this->get('/diary/999999')
            ->assertRedirect(); // a guest is sent to the login form before the diary is looked up

        $this->get('/no-such-url-at-all')
            ->assertNotFound()
            ->assertDontSee('id="notificationCenter"', false);
    }
}
