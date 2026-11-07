<?php

declare(strict_types=1);

namespace Tests\Feature\GroupTalk;

use App\Features\GroupTalk\GroupTalkController;
use App\Features\GroupTalk\GroupTalkCursor;
use App\Features\GroupTalk\TalkBody;
use App\Features\GroupTopic\TopicReadAccess;
use App\Models\Group;
use App\Models\GroupMessage;
use App\Models\Member;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** The Classic twin of the talk screen: OpenPNE 3's community timeline, server-rendered. */
class ClassicTalkScreenTest extends TalkTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['openpne.surface_mode' => 'classic_default']);
        Storage::fake('local');
    }

    private function talk(Group $group, array $query = []): string
    {
        return route('group.talk.show', ['group' => $group] + $query);
    }

    /** @return list<GroupMessage> oldest first, one second apart */
    private function seedMessages(Group $group, Member $author, int $count): array
    {
        $messages = [];
        for ($i = 0; $i < $count; $i++) {
            $messages[] = GroupMessage::factory()->create([
                'group_id' => $group->getKey(),
                'member_id' => $author->getKey(),
                'body' => "message {$i}.",
                'created_at' => Carbon::parse('2026-11-01 10:00:00')->addSeconds($i),
                'updated_at' => Carbon::parse('2026-11-01 10:00:00')->addSeconds($i),
            ]);
        }

        return $messages;
    }

    private function cursorOf(Group $group, Member $member): object
    {
        return DB::table('group_members')
            ->where('group_id', $group->getKey())->where('member_id', $member->getKey())
            ->first(['talk_read_at', 'talk_read_message_id']);
    }

    public function test_a_member_gets_the_openpne3_box_newest_first(): void
    {
        $group = $this->group();
        $member = $this->memberOf($group);
        $this->seedMessages($group, $member, 3);

        $html = $this->actingAs($member)->get($this->talk($group))
            ->assertOk()
            ->assertSee('id="page_timeline_community"', false)
            ->assertSee('class="dparts communityTimeline" id="communityTimeline"', false)
            ->assertSee('<div class="parts">', false)
            ->assertSee('data-timeline-compose', false)
            ->assertSee('data-max-length="5000"', false)
            ->assertSee(route('group.talk.mention_candidates', $group), false)
            ->assertDontSee('data-page=', false)
            ->getContent();

        $this->assertLessThan(strpos($html, 'message 1.'), strpos($html, 'message 2.'));
        $this->assertLessThan(strpos($html, 'message 0.'), strpos($html, 'message 1.'));
    }

    public function test_the_page_is_twenty_rows_with_a_pager_and_a_rows_fragment_behind_it(): void
    {
        $group = $this->group();
        $member = $this->memberOf($group);
        $messages = $this->seedMessages($group, $member, GroupTalkController::CLASSIC_PAGE + 2);

        $oldestOnPage = $messages[2];
        $before = (string) GroupTalkCursor::of($oldestOnPage);

        $this->actingAs($member)->get($this->talk($group))
            ->assertOk()
            ->assertSee('message 2.')
            ->assertDontSee('message 1.')
            ->assertSee(route('group.talk.show', ['group' => $group, 'before' => $before]), false)
            ->assertSee(route('group.talk.rows', ['group' => $group, 'before' => $before]), false);

        // The older page: the two rows left, no next.
        $this->actingAs($member)->get($this->talk($group, ['before' => $before]))
            ->assertOk()
            ->assertSee('message 1.')
            ->assertSee('message 0.')
            ->assertDontSee('message 2.')
            ->assertSee(route('group.talk.show', $group).'"', false)
            ->assertDontSee('data-next-url', false);

        $this->actingAs($member)->get(route('group.talk.rows', ['group' => $group, 'before' => $before]))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeaderMissing('Link')
            ->assertSee('message 1.')
            ->assertDontSee('<div id="Body">', false);

        $this->actingAs($member)->get(route('group.talk.rows', $group))
            ->assertOk()
            ->assertHeader('Link', '<'.route('group.talk.rows', ['group' => $group, 'before' => $before]).'>; rel="next"');
    }

    public function test_an_anchor_opens_at_the_top_of_its_page(): void
    {
        $group = $this->group();
        $member = $this->memberOf($group);
        $messages = $this->seedMessages($group, $member, 5);

        $html = $this->actingAs($member)->get($this->talk($group, ['m' => $messages[2]->getKey()]))
            ->assertOk()
            ->assertSee('name="timeline-'.$messages[2]->getKey().'"', false)
            ->assertDontSee('message 3.')
            ->assertSee('message 2.')
            ->assertSee('message 0.')
            ->assertSee(__('Show previous'))
            ->getContent();

        $this->assertLessThan(strpos($html, 'message 1.'), strpos($html, 'message 2.'));
    }

    public function test_a_malformed_anchor_or_cursor_is_the_head(): void
    {
        $group = $this->group();
        $member = $this->memberOf($group);
        $other = $this->group();
        $elsewhere = GroupMessage::factory()->create(['group_id' => $other->getKey(), 'member_id' => $member->getKey()]);
        $this->seedMessages($group, $member, 2);

        foreach ([['m' => 'abc'], ['m' => $elsewhere->getKey()], ['before' => 'nonsense'], ['before' => ['x']]] as $query) {
            $this->actingAs($member)->get($this->talk($group, $query))
                ->assertOk()
                ->assertSee('message 1.')
                ->assertDontSee(__('Show previous'));
        }
    }

    public function test_reading_follows_the_group_read_access(): void
    {
        $open = $this->group(TopicReadAccess::Everyone);
        $closed = $this->group(TopicReadAccess::MembersOnly);
        $author = $this->memberOf($open);
        $this->memberOf($closed);
        GroupMessage::factory()->create(['group_id' => $open->getKey(), 'member_id' => $author->getKey(), 'body' => 'hello all']);
        $outsider = Member::factory()->create();

        $this->actingAs($outsider)->get($this->talk($open))
            ->assertOk()
            ->assertSee('hello all')
            ->assertDontSee('data-timeline-compose', false)
            ->assertDontSee(route('group.talk.mute', $open), false);
        $this->actingAs($outsider)->get($this->talk($closed))->assertNotFound();
        $this->actingAs($outsider)->get(route('group.talk.rows', $closed))->assertNotFound();
    }

    public function test_the_form_posts_and_returns_to_the_head(): void
    {
        $group = $this->group();
        $member = $this->memberOf($group);

        $this->actingAs($member)->from($this->talk($group))
            ->post(route('group.talk.store', $group), ['body' => "typed\r\nin classic"])
            ->assertRedirect($this->talk($group))
            ->assertSessionHas('status', __('Posted.'));

        $this->assertDatabaseHas('group_messages', ['group_id' => $group->getKey(), 'body' => "typed\nin classic"]);
        $this->actingAs($member)->get($this->talk($group))->assertSee('id="flashNotice"', false)->assertSee('typed');
    }

    public function test_a_picture_alone_posts_through_the_single_image_field(): void
    {
        $group = $this->group();
        $member = $this->memberOf($group);

        $this->actingAs($member)
            ->post(route('group.talk.store', $group), ['image' => UploadedFile::fake()->image('shot.png', 40, 40)])
            ->assertRedirect($this->talk($group));

        $message = GroupMessage::query()->where('group_id', $group->getKey())->firstOrFail();
        $this->assertSame('', $message->body);
        $this->assertDatabaseHas('group_message_images', ['group_message_id' => $message->getKey(), 'number' => 1]);
        $this->actingAs($member)->get($this->talk($group))->assertSee('rel="lightbox"', false);
    }

    public function test_an_empty_form_comes_back_to_the_conversation_with_the_error_in_the_box(): void
    {
        $group = $this->group();
        $member = $this->memberOf($group);

        $this->actingAs($member)->from('/somewhere-else')
            ->post(route('group.talk.store', $group), ['body' => ''])
            ->assertRedirect($this->talk($group));

        // No session assertion between the two requests: reading the store ages the flash away.
        $this->actingAs($member)->get($this->talk($group))
            ->assertOk()
            ->assertSee('id="timeline-submit-error"', false)
            ->assertSee(__('Enter a message or attach an image.'));
        $this->assertDatabaseCount('group_messages', 0);
    }

    public function test_the_picker_rows_round_trip_into_mentions(): void
    {
        $group = $this->group();
        $member = $this->memberOf($group);
        $named = $this->memberOf($group);
        $named->forceFill(['name' => 'Taro'])->save();

        $this->actingAs($member)
            ->post(route('group.talk.store', $group), [
                'body' => 'hi @Taro',
                'mentions' => [['member_id' => $named->getKey(), 'offset' => 3, 'length' => 5]],
            ])
            ->assertRedirect($this->talk($group));

        $this->assertDatabaseHas('group_message_mentions', ['member_id' => $named->getKey(), 'offset' => 3, 'length' => 5]);
        $this->actingAs($member)->get($this->talk($group))
            ->assertSee('href="'.route('member.profile.show', $named).'" class="mention">@Taro</a>', false);
    }

    public function test_a_reply_link_prefills_the_box_and_the_post_keeps_the_lineage(): void
    {
        $group = $this->group();
        $member = $this->memberOf($group);
        $author = $this->memberOf($group);
        $author->forceFill(['name' => 'Hanako'])->save();
        $parent = GroupMessage::factory()->create(['group_id' => $group->getKey(), 'member_id' => $author->getKey(), 'body' => 'first']);

        $this->actingAs($member)->get($this->talk($group, ['reply' => $parent->getKey()]))
            ->assertOk()
            ->assertSee(__('Replying to :name', ['name' => 'Hanako']))
            ->assertSee('name="reply_to_message_id" value="'.$parent->getKey().'"', false);

        $this->actingAs($member)
            ->post(route('group.talk.store', $group), ['body' => 'second', 'reply_to_message_id' => $parent->getKey()])
            ->assertRedirect($this->talk($group));

        $this->assertDatabaseHas('group_messages', ['body' => 'second', 'in_reply_to_id' => $parent->getKey()]);
        $this->actingAs($member)->get($this->talk($group))
            ->assertSee('Hanako: first')
            ->assertSee('#timeline-'.$parent->getKey().'"', false);
    }

    public function test_a_reply_id_from_elsewhere_or_gone_offers_no_reply(): void
    {
        $group = $this->group();
        $member = $this->memberOf($group);
        $other = $this->group();
        $stranger = $this->memberOf($other);
        $stranger->forceFill(['name' => 'Secret Name'])->save();
        $foreign = GroupMessage::factory()->create(['group_id' => $other->getKey(), 'member_id' => $stranger->getKey()]);
        $gone = GroupMessage::factory()->create(['group_id' => $group->getKey(), 'member_id' => $member->getKey()]);
        $goneId = $gone->getKey();
        $gone->delete();

        foreach ([$foreign->getKey(), $goneId, 'abc'] as $reply) {
            $this->actingAs($member)->get($this->talk($group, ['reply' => $reply]))
                ->assertOk()
                ->assertDontSee('Secret Name')
                ->assertDontSee('name="reply_to_message_id"', false);
        }

        // Posting against the gone parent fails and lands on the conversation without the reply.
        $this->actingAs($member)
            ->post(route('group.talk.store', $group), ['body' => 'orphan', 'reply_to_message_id' => $goneId])
            ->assertRedirect($this->talk($group))
            ->assertSessionHasErrors('reply_to_message_id');
    }

    public function test_deleting_goes_through_the_confirm_page_or_the_dialog(): void
    {
        $group = $this->group();
        $author = $this->memberOf($group);
        $admin = $this->adminOf($group);
        $other = $this->memberOf($group);
        $mine = GroupMessage::factory()->create(['group_id' => $group->getKey(), 'member_id' => $author->getKey(), 'body' => 'mine']);
        $theirs = GroupMessage::factory()->create(['group_id' => $group->getKey(), 'member_id' => $other->getKey(), 'body' => 'theirs']);

        $confirm = fn (GroupMessage $m) => route('group.talk.delete.show', ['group' => $group, 'message' => $m]);

        $this->actingAs($author)->get($this->talk($group))
            ->assertSee($confirm($mine), false)
            ->assertDontSee($confirm($theirs), false)
            ->assertSee('id="timeline-post-delete-confirm-'.$mine->getKey().'"', false);
        $this->actingAs($author)->get($confirm($mine))->assertOk()->assertSee(__('Delete this message?'));
        $this->actingAs($author)->get($confirm($theirs))->assertNotFound();
        $this->actingAs($admin)->get($confirm($theirs))->assertOk();

        $this->actingAs($author)
            ->post(route('group.talk.delete', ['group' => $group, 'message' => $mine]))
            ->assertRedirect($this->talk($group))
            ->assertSessionHas('status', __('Message deleted.'));
        $this->assertDatabaseMissing('group_messages', ['id' => $mine->getKey()]);

        $this->actingAs($admin)
            ->withHeader('Accept', 'application/json')
            ->post(route('group.talk.delete', ['group' => $group, 'message' => $theirs]))
            ->assertOk()
            ->assertExactJson(['ok' => true]);
        $this->assertDatabaseMissing('group_messages', ['id' => $theirs->getKey()]);
    }

    public function test_a_modern_viewer_asking_for_the_confirm_page_is_sent_to_the_conversation(): void
    {
        config(['openpne.surface_mode' => 'modern_default']);
        $group = $this->group();
        $author = $this->memberOf($group);
        $mine = GroupMessage::factory()->create(['group_id' => $group->getKey(), 'member_id' => $author->getKey()]);

        $this->actingAs($author)->get(route('group.talk.delete.show', ['group' => $group, 'message' => $mine]))
            ->assertRedirect($this->talk($group, ['m' => $mine->getKey()]));
    }

    public function test_the_head_page_marks_the_room_read_as_far_as_it_rendered(): void
    {
        $group = $this->group();
        $author = $this->memberOf($group);
        $reader = $this->memberOf($group);
        // The membership snapshot is "now"; the seeded rows sit in the past, so the cursor is put
        // behind them as a join before those messages would have left it.
        DB::table('group_members')->where('group_id', $group->getKey())->where('member_id', $reader->getKey())
            ->update(['talk_read_at' => Carbon::parse('2026-10-01 00:00:00'), 'talk_read_message_id' => 0]);
        $messages = $this->seedMessages($group, $author, 3);
        $this->assertSame(0, (int) $this->cursorOf($group, $reader)->talk_read_message_id);

        // An older page and an anchor with newer rows above it move nothing.
        $this->actingAs($reader)->get($this->talk($group, ['before' => (string) GroupTalkCursor::of($messages[2])]))->assertOk();
        $this->actingAs($reader)->get($this->talk($group, ['m' => $messages[1]->getKey()]))->assertOk();
        $this->assertSame(0, (int) $this->cursorOf($group, $reader)->talk_read_message_id);

        $this->actingAs($reader)->get($this->talk($group, ['reply' => $messages[0]->getKey()]))->assertOk();
        $this->assertSame((int) $messages[2]->getKey(), (int) $this->cursorOf($group, $reader)->talk_read_message_id);

        // The anchor a mention notification links to is the newest row: that page is the head page.
        $newest = GroupMessage::factory()->create([
            'group_id' => $group->getKey(),
            'member_id' => $author->getKey(),
            'created_at' => Carbon::parse('2026-11-01 10:01:00'),
        ]);
        $this->actingAs($reader)->get($this->talk($group, ['m' => $newest->getKey()]))
            ->assertOk()
            ->assertDontSee('class="pagerRelative"', false);
        $this->assertSame((int) $newest->getKey(), (int) $this->cursorOf($group, $reader)->talk_read_message_id);
    }

    public function test_a_failed_post_carries_the_picker_rows_back_into_the_box(): void
    {
        $group = $this->group();
        $member = $this->memberOf($group);
        $named = $this->memberOf($group);

        $this->actingAs($member)
            ->post(route('group.talk.store', $group), [
                'body' => str_repeat('a', TalkBody::MAX + 1),
                'mentions' => [['member_id' => $named->getKey(), 'offset' => 0, 'length' => 5]],
            ])
            ->assertRedirect($this->talk($group));

        $this->actingAs($member)->get($this->talk($group))
            ->assertOk()
            ->assertSee('name="mentions[0][member_id]" value="'.$named->getKey().'"', false)
            ->assertSee('name="mentions[0][offset]" value="0"', false);
    }

    public function test_an_empty_head_page_and_a_non_member_leave_cursors_alone(): void
    {
        $group = $this->group(TopicReadAccess::Everyone);
        $member = $this->memberOf($group);
        $outsider = Member::factory()->create();

        $this->actingAs($member)->get($this->talk($group))->assertOk()->assertSee(__('No messages yet.'));
        $this->assertSame(0, (int) $this->cursorOf($group, $member)->talk_read_message_id);

        $this->seedMessages($group, $member, 1);
        $this->actingAs($outsider)->get($this->talk($group))->assertOk();
        $this->assertDatabaseMissing('group_members', ['group_id' => $group->getKey(), 'member_id' => $outsider->getKey()]);
    }

    public function test_the_mute_form_toggles_and_returns(): void
    {
        $group = $this->group();
        $member = $this->memberOf($group);

        $this->actingAs($member)->get($this->talk($group))->assertSee('value="'.__('Mute').'"', false);
        $this->actingAs($member)->post(route('group.talk.mute', $group), ['muted' => '1'])
            ->assertRedirect($this->talk($group))
            ->assertSessionHas('status', __('Muted.'));
        $this->assertDatabaseHas('group_members', ['group_id' => $group->getKey(), 'member_id' => $member->getKey(), 'is_talk_muted' => true]);
        $this->actingAs($member)->get($this->talk($group))->assertSee('value="'.__('Unmute').'"', false);
    }

    public function test_a_withdrawn_author_keeps_their_row(): void
    {
        $group = $this->group();
        $member = $this->memberOf($group);
        GroupMessage::factory()->withdrawnAuthor()->create(['group_id' => $group->getKey(), 'body' => 'left behind']);

        $this->actingAs($member)->get($this->talk($group))
            ->assertOk()
            ->assertSee('left behind')
            ->assertSee(__('%Withdrawn_member%'));
    }

    public function test_classic_only_serves_the_screen_to_a_phone_as_well(): void
    {
        config(['openpne.surface_mode' => 'classic_only']);
        $group = $this->group();
        $member = $this->memberOf($group);
        $this->seedMessages($group, $member, 1);

        $this->actingAs($member)
            ->withHeader('User-Agent', 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1')
            ->get($this->talk($group))
            ->assertOk()
            ->assertSee('id="page_timeline_community"', false)
            ->assertDontSee('data-page=', false);

        $this->actingAs($member)->get(route('group.talk.legacy_compat', ['group' => $group->getKey()]))
            ->assertRedirect($this->talk($group));
    }
}
