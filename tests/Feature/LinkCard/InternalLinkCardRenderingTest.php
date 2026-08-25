<?php

declare(strict_types=1);

namespace Tests\Feature\LinkCard;

use App\Features\GroupTopic\TopicReadAccess;
use App\LinkCard\InternalCardResolver;
use App\LinkCard\InternalCardTarget;
use App\LinkCard\LinkCardSerializer;
use App\LinkCard\LinkUrl;
use App\Models\Diary;
use App\Models\File;
use App\Models\Group;
use App\Models\GroupEvent;
use App\Models\GroupMember;
use App\Models\GroupMessage;
use App\Models\GroupTopic;
use App\Models\LinkCard;
use App\Models\Member;
use App\Models\MemberImage;
use App\Models\TimelinePost;
use App\Support\Feature;
use App\Support\LinkCardStatus;
use App\Support\SnsSettingKey;
use App\Support\Visibility;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Drawing a card of one of this site's own pages.
 *
 * Every test here is really the same question asked seven ways: does the card show exactly what its
 * own page would show this reader, and nothing when that page would show them nothing. Refusal and
 * absence are deliberately the same answer — a card that appeared only for records the reader may
 * not open would be an oracle over them.
 */
class InternalLinkCardRenderingTest extends TestCase
{
    use RefreshDatabase;

    private Member $author;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://sns.example.com']);
        // Off, deliberately: an internal card is drawn without it, and every test here would
        // otherwise be silently asserting the external switch instead.
        $this->setSnsSetting(SnsSettingKey::LinkCardEnabled, false);
        $this->author = Member::factory()->create();
    }

    public function test_a_diary_card_says_what_the_diary_says(): void
    {
        $diary = Diary::factory()->for($this->author)->create([
            'title' => 'A day at the coast',
            'body' => "First line\nSecond line",
            'visibility' => Visibility::Members,
        ]);

        $card = $this->draw($diary, $this->author);

        $this->assertSame('A day at the coast', $card['title']);
        $this->assertSame('First line Second line', $card['description']);
        $this->assertSame('sns.example.com', $card['domain']);
        // A page of this site does not introduce itself by name; the host is already beside the title.
        $this->assertNull($card['siteName']);
        $this->assertSame($this->urlFor($diary), $card['url']);
    }

    public function test_a_diary_card_follows_the_diary_rule(): void
    {
        $diary = Diary::factory()->for($this->author)->create(['visibility' => Visibility::Friends]);
        $stranger = Member::factory()->create();
        $friend = Member::factory()->create();
        $this->befriend($this->author, $friend);

        $this->assertNotNull($this->draw($diary, $friend));
        $this->assertNull($this->draw($diary, $stranger));
        $this->assertNull($this->draw($diary, null));
    }

    public function test_a_web_public_diary_draws_for_a_signed_out_reader(): void
    {
        $this->setSnsSetting(SnsSettingKey::DiaryAllowWebPublic, true);
        $diary = Diary::factory()->for($this->author)->create(['title' => 'Open to the web', 'visibility' => Visibility::Open]);

        $this->assertSame('Open to the web', $this->draw($diary, null)['title']);
    }

    public function test_a_topic_card_follows_the_board_rule(): void
    {
        [$group, $member] = $this->membersOnlyGroup();
        $topic = GroupTopic::factory()->for($group)->for($this->author, 'member')->create(['name' => 'A topic', 'body' => 'Words']);

        $card = $this->draw($topic, $member);
        $this->assertSame('A topic', $card['title']);
        $this->assertSame('Words', $card['description']);
        $this->assertNull($this->draw($topic, Member::factory()->create()));
        $this->assertNull($this->draw($topic, null));
    }

    public function test_an_event_card_follows_the_board_rule(): void
    {
        [$group, $member] = $this->membersOnlyGroup();
        $event = GroupEvent::factory()->for($group)->for($this->author, 'member')->create(['name' => 'A meetup', 'body' => 'Words']);

        $this->assertSame('A meetup', $this->draw($event, $member)['title']);
        $this->assertNull($this->draw($event, Member::factory()->create()));
    }

    public function test_a_talk_message_card_follows_the_rooms_rule(): void
    {
        [$group, $member] = $this->membersOnlyGroup();
        $message = GroupMessage::factory()->for($group)->for($this->author, 'author')->create(['body' => 'Something said in the room']);

        $card = $this->draw($message, $member);
        $this->assertSame(__('Message from :name', ['name' => $this->author->name]), $card['title']);
        $this->assertSame('Something said in the room', $card['description']);
        $this->assertNull($this->draw($message, Member::factory()->create()));
    }

    public function test_a_talk_message_card_answers_only_through_its_own_rooms_path(): void
    {
        // The conversation page refuses an anchor naming another room's message, so a card drawn
        // through the wrong room's path would describe a message its own URL does not open — for a
        // reader allowed into both rooms, not an authorization question at all.
        [$group, $member] = $this->membersOnlyGroup();
        $other = Group::factory()->create();
        GroupMember::factory()->create(['group_id' => $other->id, 'member_id' => $member->id]);
        $message = GroupMessage::factory()->for($group)->for($this->author, 'author')->create(['body' => 'Said in the first room']);

        $wrongRoom = "https://sns.example.com/groups/{$other->id}/talk?m={$message->getKey()}";

        $this->assertNull($this->drawUrl($wrongRoom, InternalCardTarget::TalkMessage, (int) $message->getKey(), $member));
    }

    public function test_a_talk_message_by_a_withdrawn_member_still_draws(): void
    {
        [$group, $member] = $this->membersOnlyGroup();
        $message = GroupMessage::factory()->for($group)->create(['member_id' => null, 'body' => 'Left behind']);

        $this->assertSame(__('Message from :name', ['name' => __('Withdrawn member')]), $this->draw($message, $member)['title']);
    }

    public function test_a_group_card_draws_for_any_signed_in_member(): void
    {
        // A group's own page is browsable by every member — only its boards carry a read gate — and
        // FilePolicy applies that same rule to its top image.
        $group = Group::factory()->create(['name' => 'The group', 'description' => "A line\nAnother"]);

        $card = $this->draw($group, Member::factory()->create());
        $this->assertSame('The group', $card['title']);
        $this->assertSame('A line Another', $card['description']);
        $this->assertNull($this->draw($group, null));
    }

    public function test_a_member_card_needs_a_web_public_profile_for_a_signed_out_reader(): void
    {
        // The block policy lets a guest through by design — a guest is nobody to block — so the
        // web-public switch on the profile is the only thing that answers them.
        $open = Member::factory()->create(['name' => 'Open profile', 'profile_visibility' => Visibility::Open]);
        $closed = Member::factory()->create(['profile_visibility' => Visibility::Members]);

        $this->assertSame('Open profile', $this->draw($open, null)['title']);
        $this->assertNull($this->draw($closed, null));
        $this->assertNotNull($this->draw($closed, Member::factory()->create()));
    }

    public function test_a_member_card_is_withheld_from_someone_that_member_has_blocked(): void
    {
        $subject = Member::factory()->create(['profile_visibility' => Visibility::Open]);
        $blocked = Member::factory()->create();
        DB::table('member_blocks')->insert(['blocker_id' => $subject->getKey(), 'blocked_id' => $blocked->getKey()]);

        $this->assertNull($this->draw($subject, $blocked));
        $this->assertNotNull($this->draw($subject, Member::factory()->create()));
    }

    public function test_a_timeline_post_card_says_who_wrote_it(): void
    {
        $post = TimelinePost::factory()->for($this->author)->create(['body' => 'A thought', 'visibility' => Visibility::Members]);

        $card = $this->draw($post, Member::factory()->create());
        $this->assertSame(__('%post_activity% by :name', ['name' => $this->author->name]), $card['title']);
        $this->assertSame('A thought', $card['description']);
    }

    public function test_a_reply_card_is_authorised_by_its_thread_root(): void
    {
        // The reply's own rule would admit the *replier's* friends, who are not who the thread was
        // gated for and who cannot open it at all.
        $replier = Member::factory()->create();
        $viewer = Member::factory()->create();
        $this->befriend($replier, $viewer);
        $root = TimelinePost::factory()->for($this->author)->create(['visibility' => Visibility::Friends]);
        $reply = TimelinePost::factory()->for($replier)->create([
            'visibility' => Visibility::Friends,
            'in_reply_to_id' => $root->id,
        ]);

        $this->assertNull($this->draw($reply, $viewer), "A replier's friend was admitted to a thread they cannot open.");
        $this->befriend($this->author, $viewer);
        $this->assertNotNull($this->draw($reply, $viewer));
    }

    public function test_a_record_that_is_gone_draws_nothing(): void
    {
        $diary = Diary::factory()->for($this->author)->create(['visibility' => Visibility::Members]);
        $url = $this->urlFor($diary);
        $id = $diary->id;
        $diary->delete();

        $this->assertNull($this->drawUrl($url, InternalCardTarget::Diary, $id, $this->author));
    }

    public function test_switching_the_unit_off_takes_its_cards_with_it(): void
    {
        $diary = Diary::factory()->for($this->author)->create(['visibility' => Visibility::Members]);
        $this->assertNotNull($this->draw($diary, $this->author));

        $this->setSnsSetting(Feature::Diary->settingKey(), false);

        $this->assertNull($this->draw($diary, $this->author));
    }

    public function test_switching_groups_off_takes_a_topic_card_with_it(): void
    {
        // Feature::enabled() resolves ancestors, so a topic's card stops when groups do.
        [$group, $member] = $this->membersOnlyGroup();
        $topic = GroupTopic::factory()->for($group)->for($this->author, 'member')->create();
        $this->assertNotNull($this->draw($topic, $member));

        $this->setSnsSetting(Feature::Group->settingKey(), false);

        $this->assertNull($this->draw($topic, $member));
    }

    public function test_the_external_switch_does_not_hide_an_internal_card(): void
    {
        // setUp already leaves it off; turning it on must not change the answer either.
        $diary = Diary::factory()->for($this->author)->create(['visibility' => Visibility::Members]);
        $this->assertNotNull($this->draw($diary, $this->author));

        $this->setSnsSetting(SnsSettingKey::LinkCardEnabled, true);

        $this->assertNotNull($this->draw($diary, $this->author));
    }

    public function test_renaming_this_site_stops_the_card_rather_than_relabelling_the_link(): void
    {
        // The card is drawn beside its own URL, which is what the reader clicks. Once that address
        // is somebody else's, describing it with a record of ours would describe one page while
        // linking to another — and whoever answers the old host would be doing the describing.
        $diary = Diary::factory()->for($this->author)->create(['visibility' => Visibility::Members]);
        $this->assertNotNull($this->draw($diary, $this->author));

        config(['app.url' => 'https://renamed.example.com']);

        $this->assertNull($this->draw($diary, $this->author));
    }

    public function test_a_row_of_ours_that_names_no_record_draws_nothing(): void
    {
        // What the fetch job leaves behind when it repairs an OpenPNE 3 spelling: a row that is
        // ours, examined, and points at nothing.
        $this->assertNull($this->drawRow(
            'https://sns.example.com/diary',
            ['internal_context' => null, 'internal_record_id' => null],
            $this->author,
        ));
    }

    public function test_a_row_whose_pointer_disagrees_with_its_url_draws_nothing(): void
    {
        $diary = Diary::factory()->for($this->author)->create(['visibility' => Visibility::Members]);
        $other = Diary::factory()->for($this->author)->create(['title' => 'Another diary', 'visibility' => Visibility::Members]);

        $this->assertNull(
            $this->drawUrl($this->urlFor($diary), InternalCardTarget::Diary, $other->id, $this->author),
            'A row described a record its own URL does not name.',
        );
    }

    public function test_a_row_with_a_missing_or_unknown_pointer_draws_nothing(): void
    {
        $diary = Diary::factory()->for($this->author)->create(['visibility' => Visibility::Members]);
        $url = $this->urlFor($diary);

        foreach ([
            'no context' => ['internal_context' => null, 'internal_record_id' => $diary->id],
            'no id' => ['internal_context' => 'diary', 'internal_record_id' => null],
            'a kind this app no longer has' => ['internal_context' => 'poll', 'internal_record_id' => $diary->id],
        ] as $why => $pointer) {
            $this->assertNull($this->drawRow($url, $pointer, $this->author), "A row with {$why} drew a card.");
        }
    }

    public function test_the_card_carries_the_records_own_picture(): void
    {
        $subject = Member::factory()->create(['profile_visibility' => Visibility::Open]);
        $file = File::factory()->create(['type' => 'image/png', 'width' => 1200, 'height' => 630]);
        MemberImage::factory()->create(['member_id' => $subject->getKey(), 'file_id' => $file->getKey()]);

        $card = $this->draw($subject, $this->author);

        // Served through the file route, so FilePolicy authorises the bytes against the same record
        // whose rule admitted the card — never through /linkCard/, which is for fetched pictures.
        $this->assertSame($file->thumbnailUrl(120, 120, square: true), $card['imageUrl']);
        $this->assertSame(1200, $card['imageWidth']);
        // A big landscape picture takes the wide shape, by the same threshold a fetched card uses.
        $this->assertSame('wide', $card['layout']);
        $this->assertSame($file->thumbnailUrl(640, 640), $card['fitSources'][1]['url']);
    }

    public function test_a_file_that_is_not_an_image_gets_no_url_rather_than_a_broken_one(): void
    {
        // As the fetched path does (CardContext::imageUrl): thumbnailUrl falls back to jpg for a
        // format it cannot name, and that address 404s — a card is better bare than broken.
        $subject = Member::factory()->create(['profile_visibility' => Visibility::Open]);
        $file = File::factory()->create(['type' => 'application/pdf', 'width' => 1200, 'height' => 630]);
        MemberImage::factory()->create(['member_id' => $subject->getKey(), 'file_id' => $file->getKey()]);

        $card = $this->draw($subject, $this->author);

        $this->assertNull($card['imageUrl']);
        $this->assertNull($card['imageWidth']);
        $this->assertSame('compact', $card['layout']);
        $this->assertSame([], $card['fitSources']);
    }

    public function test_a_small_picture_takes_the_compact_shape_and_ships_no_fit_ladder(): void
    {
        $subject = Member::factory()->create(['profile_visibility' => Visibility::Open]);
        $file = File::factory()->create(['type' => 'image/png', 'width' => 100, 'height' => 100]);
        MemberImage::factory()->create(['member_id' => $subject->getKey(), 'file_id' => $file->getKey()]);

        $card = $this->draw($subject, $this->author);

        $this->assertSame('compact', $card['layout']);
        $this->assertSame([], $card['fitSources']);
    }

    public function test_both_surfaces_draw_it(): void
    {
        $target = Diary::factory()->for($this->author)->create(['title' => 'The linked diary', 'visibility' => Visibility::Members]);
        $carrier = $this->carrier($this->row($this->urlFor($target), ['internal_context' => 'diary', 'internal_record_id' => $target->id]));

        $this->actingAs($this->author)->get("/diary/{$carrier->id}")
            ->assertOk()
            ->assertSee('The linked diary')
            ->assertSee('sns.example.com');

        config(['openpne.surface_mode' => 'modern_default']);
        $this->actingAs($this->author)->get("/diary/{$carrier->id}")
            ->assertInertia(fn ($page) => $page->where('diary.linkCard.title', 'The linked diary')->etc());
    }

    public function test_a_signed_out_reader_sees_only_what_the_linked_page_would_show_them(): void
    {
        // End to end rather than through the serializer, because the Classic component resolves the
        // reader itself: a template that dropped that would show every guest every card.
        $this->setSnsSetting(SnsSettingKey::DiaryAllowWebPublic, true);
        $hidden = Diary::factory()->for($this->author)->create(['title' => 'Members only', 'visibility' => Visibility::Members]);
        $carrier = $this->carrier($this->row($this->urlFor($hidden), ['internal_context' => 'diary', 'internal_record_id' => $hidden->id]));
        $carrier->update(['visibility' => Visibility::Open]);

        $this->get("/diary/{$carrier->id}")->assertOk()->assertDontSee('Members only');
        $this->actingAs($this->author)->get("/diary/{$carrier->id}")->assertOk()->assertSee('Members only');
    }

    /**
     * The pointer row for $url — one per URL, as the unique index enforces, so a test asking the
     * same question of two readers reuses it rather than minting a second.
     *
     * @param  array<string, mixed>  $pointer
     */
    private function row(string $url, array $pointer): LinkCard
    {
        return LinkCard::updateOrCreate(['url_hash' => LinkUrl::hash($url)], $pointer + [
            'url' => $url,
            'status' => LinkCardStatus::Internal,
            'title' => null,
            'description' => null,
            'site_name' => null,
            'fetched_at' => null,
            'expires_at' => null,
            'next_attempt_at' => null,
        ]);
    }

    /** A body pointing at $card — the diary a reader is actually looking at. */
    private function carrier(LinkCard $card): Diary
    {
        return Diary::factory()->for($this->author)->create([
            'title' => 'A body with a link in it',
            'link_card_id' => $card->id,
            'link_card_synced_at' => now(),
        ]);
    }

    /** The card a body linking $record draws for $viewer. */
    private function draw(Model $record, ?Member $viewer): ?array
    {
        return $this->drawUrl($this->urlFor($record), $this->kindOf($record), (int) $record->getKey(), $viewer);
    }

    private function drawUrl(string $url, InternalCardTarget $target, int $id, ?Member $viewer): ?array
    {
        return $this->drawRow($url, ['internal_context' => $target->value, 'internal_record_id' => $id], $viewer);
    }

    /** @param  array<string, mixed>  $pointer */
    private function drawRow(string $url, array $pointer, ?Member $viewer): ?array
    {
        $carrier = $this->carrier($this->row($url, $pointer));

        // A request begins with an empty one; holding the previous call's records would let a test
        // read a target it has since deleted.
        $this->app->forgetInstance(InternalCardResolver::class);

        return LinkCardSerializer::card(Diary::with('linkCard')->findOrFail($carrier->id), $viewer);
    }

    private function kindOf(Model $record): InternalCardTarget
    {
        return match ($record::class) {
            Diary::class => InternalCardTarget::Diary,
            GroupTopic::class => InternalCardTarget::Topic,
            GroupEvent::class => InternalCardTarget::Event,
            TimelinePost::class => InternalCardTarget::TimelinePost,
            Group::class => InternalCardTarget::Group,
            Member::class => InternalCardTarget::Member,
            GroupMessage::class => InternalCardTarget::TalkMessage,
        };
    }

    /** The canonical address of $record, as a member would have copied it out of the address bar. */
    private function urlFor(Model $record): string
    {
        $path = match ($record::class) {
            Diary::class => "/diary/{$record->getKey()}",
            GroupTopic::class => "/topics/{$record->getKey()}",
            GroupEvent::class => "/events/{$record->getKey()}",
            TimelinePost::class => "/timeline/{$record->getKey()}",
            Group::class => "/groups/{$record->getKey()}",
            Member::class => "/member/{$record->getKey()}",
            GroupMessage::class => "/groups/{$record->group_id}/talk?m={$record->getKey()}",
        };

        return 'https://sns.example.com'.$path;
    }

    /** @return array{Group, Member} a members-only board and someone who may read it */
    private function membersOnlyGroup(): array
    {
        $group = Group::factory()->create(['topic_read_access' => TopicReadAccess::MembersOnly]);
        $member = Member::factory()->create();
        GroupMember::factory()->create(['group_id' => $group->id, 'member_id' => $member->id]);
        GroupMember::factory()->create(['group_id' => $group->id, 'member_id' => $this->author->id]);

        return [$group, $member];
    }

    private function befriend(Member $a, Member $b): void
    {
        DB::table('friendships')->insert([
            ['member_id' => $a->getKey(), 'friend_id' => $b->getKey()],
            ['member_id' => $b->getKey(), 'friend_id' => $a->getKey()],
        ]);
    }
}
