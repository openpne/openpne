<?php

namespace Tests\Feature\Home;

use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\Gadget;
use App\Models\Member;
use App\Models\Message;
use App\Models\MessageRecipient;
use App\Services\GadgetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ClassicHomeTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_member_sees_the_classic_home(): void
    {
        $member = Member::factory()->create(['name' => 'Hanako']);

        $this->actingAs($member)->get('/')
            ->assertOk()
            ->assertSee('id="page_member_home"', false) // body-id hook
            ->assertSee('id="home_index"', false)
            ->assertSee('Hanako');
    }

    public function test_guest_at_root_is_redirected_to_login(): void
    {
        $this->get('/')->assertRedirect('/login');
    }

    public function test_root_redirects_to_the_dashboard_when_the_default_surface_is_modern(): void
    {
        config(['openpne.surface_mode' => 'modern_default']);
        $member = Member::factory()->create();

        $this->actingAs($member)->get('/')->assertRedirect('/dashboard');
    }

    public function test_member_index_alias_redirects_to_root(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)->get('/member')->assertRedirect('/');
    }

    public function test_admin_transfer_nominee_sees_a_caution_linking_to_the_community(): void
    {
        $member = Member::factory()->create();
        $community = Community::factory()->create(['name' => 'Runners Club']);
        CommunityMember::factory()->create(['community_id' => $community->getKey(), 'member_id' => $member->getKey()]);
        $community->forceFill(['pending_admin_member_id' => $member->getKey()])->save();

        // The CI DB configures no gadgets, so this exercises the no-gadgets fallback branch (the
        // one that renders id="home_index"), where the caution must also appear.
        $this->actingAs($member)->get('/')
            ->assertOk()
            ->assertSee('id="home_index"', false)
            ->assertSee('Runners Club')
            ->assertSee(e(route('community.show', $community)), false);
    }

    public function test_admin_transfer_caution_also_renders_on_a_gadget_configured_home(): void
    {
        $member = Member::factory()->create();
        $community = Community::factory()->create(['name' => 'Runners Club']);
        CommunityMember::factory()->create(['community_id' => $community->getKey(), 'member_id' => $member->getKey()]);
        $community->forceFill(['pending_admin_member_id' => $member->getKey()])->save();

        // With a gadget configured, home takes the gadget-sections branch (contentTop seam), not the
        // no-gadgets fallback — the caution must render there too.
        Gadget::create(['context' => 'home', 'zone' => 'contents', 'name' => 'informationBox', 'sort_order' => 10]);
        app(GadgetService::class)->clearCache();

        $this->actingAs($member)->get('/')
            ->assertOk()
            ->assertDontSee('id="home_index"', false)
            ->assertSee('Runners Club')
            ->assertSee(e(route('community.show', $community)), false);
    }

    public function test_several_cautions_share_one_information_box(): void
    {
        $member = Member::factory()->create();
        foreach (['Runners Club', 'Cycling Club'] as $name) {
            $community = Community::factory()->create(['name' => $name]);
            CommunityMember::factory()->create(['community_id' => $community->getKey(), 'member_id' => $member->getKey()]);
            $community->forceFill(['pending_admin_member_id' => $member->getKey()])->save();
        }

        $content = (string) $this->actingAs($member)->get('/')->assertOk()->getContent();

        // OpenPNE 3 hung every caution off the single informationBox body — one box, N lines.
        $this->assertSame(1, substr_count($content, 'class="parts informationBox"'));
        $this->assertSame(2, substr_count($content, '<p class="caution">'));
    }

    public function test_non_nominee_sees_no_admin_transfer_caution(): void
    {
        $member = Member::factory()->create();
        $nominee = Member::factory()->create();
        $community = Community::factory()->create(['name' => 'Runners Club']);
        $community->forceFill(['pending_admin_member_id' => $nominee->getKey()])->save();

        $this->actingAs($member)->get('/')
            ->assertOk()
            ->assertDontSee(e(route('community.show', $community)), false);
    }

    public function test_unread_messages_and_friend_requests_each_add_a_caution(): void
    {
        $viewer = Member::factory()->create();
        $sender = Member::factory()->create();
        DB::table('friend_requests')->insert(['requester_id' => $sender->getKey(), 'target_id' => $viewer->getKey()]);
        $message = Message::factory()->create(['sender_id' => $sender->getKey()]);
        MessageRecipient::factory()->create(['message_id' => $message->getKey(), 'recipient_id' => $viewer->getKey()]);

        $content = (string) $this->actingAs($viewer)->get('/')->assertOk()->getContent();

        $this->assertSame(1, substr_count($content, 'class="parts informationBox"'));
        // The friend line is OpenPNE 3's `p.caution`; the message line is its own `ul > li` with the
        // star and an inner span — _unreadMessage.php never matched the others.
        $this->assertStringContainsString('<a href="'.e(route('friend.requests')).'">'.e(__('Check requests')).'</a>', $content);
        $this->assertStringContainsString('★<span class="caution">'.e(__('There are new :count messages!', ['count' => 1])).'</span>', $content);
        $this->assertStringContainsString('<a href="'.e(route('message.index')).'"><strong>'.e(__('Read messages')).'</strong></a>', $content);
    }

    public function test_cautions_keep_the_openpne3_order(): void
    {
        $viewer = Member::factory()->create();
        $sender = Member::factory()->create();
        $community = Community::factory()->create(['name' => 'Runners Club']);
        CommunityMember::factory()->create(['community_id' => $community->getKey(), 'member_id' => $viewer->getKey()]);
        $community->forceFill(['pending_admin_member_id' => $viewer->getKey()])->save();
        DB::table('friend_requests')->insert(['requester_id' => $sender->getKey(), 'target_id' => $viewer->getKey()]);
        $message = Message::factory()->create(['sender_id' => $sender->getKey()]);
        MessageRecipient::factory()->create(['message_id' => $message->getKey(), 'recipient_id' => $viewer->getKey()]);

        $content = (string) $this->actingAs($viewer)->get('/')->assertOk()->getContent();

        // OpenPNE 3 sorted the customize attribute names: cautionAboutChangeAdminRequest,
        // cautionAboutFriendPre, then unreadMessage.
        $friendLine = strpos($content, e(__('Check requests')));
        $messageLine = strpos($content, e(__('Read messages')));

        $this->assertGreaterThan(strpos($content, 'Runners Club'), $friendLine);
        $this->assertGreaterThan($friendLine, $messageLine);
    }

    public function test_a_member_with_nothing_waiting_gets_no_information_box(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)->get('/')
            ->assertOk()
            ->assertDontSee('class="parts informationBox"', false);
    }

    /**
     * The cautions count the mailbox and the request list; the header badges count the notification
     * center's own rows (NotificationCenterTest). Those answer different questions and are not
     * reconciled — OpenPNE 3's diverged the same way, its badges coming from the event store while
     * these cautions ran their own queries. What this pins is that the cautions keep asking layer 1,
     * so a member with nothing in the center is still told what is waiting.
     */
    public function test_the_cautions_count_the_mailbox_rather_than_the_notification_rows(): void
    {
        $viewer = Member::factory()->create();
        $senders = Member::factory()->count(3)->create();
        foreach ($senders as $sender) {
            DB::table('friend_requests')->insert(['requester_id' => $sender->getKey(), 'target_id' => $viewer->getKey()]);
        }
        foreach ($senders->take(2) as $sender) {
            $message = Message::factory()->create(['sender_id' => $sender->getKey()]);
            MessageRecipient::factory()->create(['message_id' => $message->getKey(), 'recipient_id' => $viewer->getKey()]);
        }

        $content = (string) $this->actingAs($viewer)->get('/')->assertOk()->getContent();

        $this->assertStringContainsString(e(__('There are new :count messages!', ['count' => 2])), $content);
        $this->assertStringContainsString(e(__("You've gotten :count %friend% requests", ['count' => 3])), $content);
        // No notification rows were written, so the center has nothing to badge.
        $this->assertStringNotContainsString('id="nc_icon', $content);
    }
}
