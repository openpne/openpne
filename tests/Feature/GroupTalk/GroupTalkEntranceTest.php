<?php

namespace Tests\Feature\GroupTalk;

use App\Features\GroupTopic\TopicReadAccess;
use App\Models\GroupMessage;
use App\Models\Member;
use App\Support\SnsSettingKey;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;

/**
 * The talk entrance on a group's top page. It asks its own two questions — the unit, and this
 * group's read gate — rather than borrowing the topic board's "may the viewer read the boards"
 * seam: the two units are switched independently, and a site running talk without the board would
 * otherwise have a readable conversation with nothing linking to it.
 */
class GroupTalkEntranceTest extends TalkTestCase
{
    public function test_a_reader_of_an_everyone_group_gets_the_entrance(): void
    {
        $group = $this->group(TopicReadAccess::Everyone);

        $this->actingAs(Member::factory()->create())
            ->get("/groups/{$group->getKey()}")
            ->assertInertia(fn ($page) => $page->where('canViewTalk', true));
    }

    /** The regression this prop exists for: the boards are off, talk is on and readable. */
    public function test_the_entrance_survives_the_topic_board_being_switched_off(): void
    {
        $this->setSnsSetting(SnsSettingKey::FeatureGroupTopicEnabled, false);
        $group = $this->group(TopicReadAccess::Everyone);

        $this->actingAs($this->memberOf($group))
            ->get("/groups/{$group->getKey()}")
            ->assertInertia(fn ($page) => $page
                // The board's own card is gone…
                ->where('recentTopics', null)
                // …and talk's entrance is not.
                ->where('canViewTalk', true));
    }

    public function test_a_non_member_gets_no_entrance_to_a_members_only_group(): void
    {
        $group = $this->group(TopicReadAccess::MembersOnly);

        $this->actingAs(Member::factory()->create())
            ->get("/groups/{$group->getKey()}")
            ->assertInertia(fn ($page) => $page->where('canViewTalk', false));

        $this->actingAs($this->memberOf($group))
            ->get("/groups/{$group->getKey()}")
            ->assertInertia(fn ($page) => $page->where('canViewTalk', true));
    }

    public function test_no_entrance_while_the_unit_is_switched_off(): void
    {
        $this->setSnsSetting(SnsSettingKey::FeatureGroupTalkEnabled, false);
        $group = $this->group(TopicReadAccess::Everyone);

        $this->actingAs($this->memberOf($group))
            ->get("/groups/{$group->getKey()}")
            ->assertInertia(fn ($page) => $page->where('canViewTalk', false));
    }

    public function test_no_entrance_while_groups_themselves_are_switched_off(): void
    {
        $group = $this->group();
        $member = $this->memberOf($group);
        $this->setSnsSetting(SnsSettingKey::FeatureGroupEnabled, false);
        $this->freshRequestState();

        // The whole group page goes with its unit; the prop never gets the chance to be wrong.
        $this->actingAs($member)->get("/groups/{$group->getKey()}")->assertNotFound();
    }

    public function test_the_card_previews_the_newest_message(): void
    {
        $group = $this->group(TopicReadAccess::Everyone);
        $author = $this->memberOf($group);
        $author->forceFill(['name' => 'Alice'])->save();
        GroupMessage::factory()->create([
            'group_id' => $group->getKey(), 'member_id' => $author->getKey(), 'body' => 'older',
            'created_at' => Carbon::parse('2026-08-14 09:00:00'), 'updated_at' => Carbon::parse('2026-08-14 09:00:00'),
        ]);
        GroupMessage::factory()->create([
            'group_id' => $group->getKey(), 'member_id' => $author->getKey(), 'body' => 'newest',
            'created_at' => Carbon::parse('2026-08-14 10:00:00'), 'updated_at' => Carbon::parse('2026-08-14 10:00:00'),
        ]);

        $this->actingAs($author)->get("/groups/{$group->getKey()}")
            ->assertInertia(fn ($page) => $page
                ->where('talkPreview.body', 'newest')
                ->where('talkPreview.authorName', 'Alice'));
    }

    public function test_a_withdrawn_author_previews_with_no_name(): void
    {
        $group = $this->group(TopicReadAccess::Everyone);
        GroupMessage::factory()->withdrawnAuthor()->create(['group_id' => $group->getKey(), 'body' => 'still here']);

        $this->actingAs($this->memberOf($group))->get("/groups/{$group->getKey()}")
            ->assertInertia(fn ($page) => $page
                ->where('talkPreview.body', 'still here')
                ->where('talkPreview.authorName', null));
    }

    /**
     * The card reads through LatestGroupMessage, which is not the room list's query — the stand-in
     * for a message with nothing but pictures has to be pinned on this path of its own.
     */
    public function test_a_picture_only_message_previews_as_a_picture(): void
    {
        $group = $this->group(TopicReadAccess::Everyone);
        $author = $this->memberOf($group);

        $this->actingAs($author)
            ->post("/groups/{$group->getKey()}/talk", ['images' => [UploadedFile::fake()->image('shot.png', 40, 40)]])
            ->assertCreated();

        $this->actingAs($author)->get("/groups/{$group->getKey()}")
            ->assertInertia(fn ($page) => $page->where('talkPreview.body', __('Image')));
    }

    public function test_an_empty_conversation_previews_nothing(): void
    {
        $group = $this->group();

        $this->actingAs($this->memberOf($group))->get("/groups/{$group->getKey()}")
            ->assertInertia(fn ($page) => $page->where('talkPreview', null)->where('talkUnread', 0));
    }

    /** A non-member reader holds no membership row, so no cursor — zero, not "everything". */
    public function test_a_non_member_reader_sees_the_preview_with_no_unread(): void
    {
        $group = $this->group(TopicReadAccess::Everyone);
        GroupMessage::factory()->create([
            'group_id' => $group->getKey(), 'member_id' => $this->memberOf($group)->getKey(), 'body' => 'hello',
        ]);

        $this->actingAs(Member::factory()->create())->get("/groups/{$group->getKey()}")
            ->assertInertia(fn ($page) => $page
                ->where('talkPreview.body', 'hello')
                ->where('talkUnread', 0));
    }

    /** Mute silences the nav badge, not the group's own card. */
    public function test_a_muted_group_still_shows_its_unread_on_the_card(): void
    {
        $group = $this->group();
        $viewer = $this->memberOf($group);
        GroupMessage::factory()->count(2)->create([
            'group_id' => $group->getKey(), 'member_id' => $this->memberOf($group)->getKey(),
        ]);
        $this->actingAs($viewer)->postJson("/groups/{$group->getKey()}/talk/mute", ['muted' => true])->assertNoContent();

        $this->actingAs($viewer)->get("/groups/{$group->getKey()}")
            ->assertInertia(fn ($page) => $page->where('talkUnread', 2));
    }

    /** The preview carries a member's words, so the gate is asked before the conversation is read. */
    public function test_the_conversation_is_not_read_when_the_gate_refuses(): void
    {
        $group = $this->group(TopicReadAccess::MembersOnly);
        GroupMessage::factory()->create(['group_id' => $group->getKey(), 'body' => 'private']);

        $this->actingAs(Member::factory()->create())->get("/groups/{$group->getKey()}")
            ->assertInertia(fn ($page) => $page
                ->where('canViewTalk', false)
                ->where('talkPreview', null)
                ->where('talkUnread', 0));
    }
}
