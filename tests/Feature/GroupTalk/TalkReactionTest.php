<?php

namespace Tests\Feature\GroupTalk;

use App\Features\Group\Actions\DeleteGroup;
use App\Features\GroupTalk\Queries\MessageReactors;
use App\Features\GroupTopic\TopicReadAccess;
use App\Models\GroupMember;
use App\Models\Member;
use App\Models\Reaction;
use App\Support\SnsSettingKey;

class TalkReactionTest extends TalkReactionTestCase
{
    public function test_a_non_member_cannot_react_to_a_conversation_it_may_read(): void
    {
        // Everyone: a signed-in outsider may read the room and still not speak in it.
        $group = $this->group();
        $message = $this->message($group);
        $outsider = Member::factory()->create();

        $this->actingAs($outsider)->getJson("/groups/{$group->getKey()}/talk/messages")->assertOk();

        $this->react($outsider, $group, $message)->assertNotFound();
        $this->assertDatabaseCount('reactions', 0);
    }

    public function test_a_non_member_cannot_read_the_reactors_of_a_members_only_group(): void
    {
        $group = $this->group(TopicReadAccess::MembersOnly);
        $message = $this->message($group);

        $this->actingAs(Member::factory()->create())
            ->getJson("/groups/{$group->getKey()}/talk/messages/{$message->getKey()}/reactions")
            ->assertNotFound();
    }

    public function test_a_member_reacting_writes_a_row_and_is_answered_with_the_count(): void
    {
        $group = $this->group();
        $message = $this->message($group);
        $member = $this->memberOf($group);

        $this->react($member, $group, $message)
            ->assertOk()
            ->assertExactJson(['reactions' => [['emoji' => $this->emoji(0), 'count' => 1, 'mine' => true]]]);

        $this->assertDatabaseHas('reactions', [
            'reactable_type' => 'groupMessage',
            'reactable_id' => $message->getKey(),
            'member_id' => $member->getKey(),
            'emoji' => $this->emoji(0),
        ]);
    }

    public function test_someone_elses_reaction_counts_but_is_not_mine(): void
    {
        $group = $this->group();
        $message = $this->message($group);
        $reactor = $this->memberOf($group);
        $other = $this->memberOf($group);

        $this->react($reactor, $group, $message)->assertOk();

        $this->actingAs($other)
            ->getJson("/groups/{$group->getKey()}/talk/messages")
            ->assertJsonPath('messages.0.reactions', [['emoji' => $this->emoji(0), 'count' => 1, 'mine' => false]]);
    }

    public function test_reacting_twice_with_the_same_emoji_changes_nothing(): void
    {
        $group = $this->group();
        $message = $this->message($group);
        $member = $this->memberOf($group);

        $this->react($member, $group, $message)->assertOk();
        $version = $this->seq($group);

        $this->react($member, $group, $message)
            ->assertOk()
            ->assertJsonPath('reactions.0.count', 1);

        $this->assertDatabaseCount('reactions', 1);
        $this->assertSame($version, $this->seq($group), 'a no-op moved the reaction version');
    }

    public function test_a_second_emoji_from_the_same_member_is_an_addition(): void
    {
        $group = $this->group();
        $message = $this->message($group);
        $member = $this->memberOf($group);

        $this->react($member, $group, $message, $this->emoji(0))->assertOk();
        $this->react($member, $group, $message, $this->emoji(1))
            ->assertOk()
            ->assertExactJson(['reactions' => [
                ['emoji' => $this->emoji(0), 'count' => 1, 'mine' => true],
                ['emoji' => $this->emoji(1), 'count' => 1, 'mine' => true],
            ]]);

        $this->assertDatabaseCount('reactions', 2);
    }

    public function test_removing_a_reaction_takes_its_row_away(): void
    {
        $group = $this->group();
        $message = $this->message($group);
        $member = $this->memberOf($group);

        $this->react($member, $group, $message)->assertOk();

        $this->unreact($member, $group, $message)
            ->assertOk()
            ->assertExactJson(['reactions' => []]);

        $this->assertDatabaseCount('reactions', 0);
    }

    public function test_removing_one_that_is_not_there_changes_nothing(): void
    {
        $group = $this->group();
        $message = $this->message($group);
        $member = $this->memberOf($group);
        $version = $this->seq($group);

        $this->unreact($member, $group, $message)->assertOk()->assertExactJson(['reactions' => []]);

        $this->assertSame($version, $this->seq($group), 'a no-op moved the reaction version');
    }

    public function test_an_emoji_outside_the_vocabulary_is_refused(): void
    {
        $group = $this->group();
        $message = $this->message($group);

        $this->react($this->memberOf($group), $group, $message, "\u{1F92F}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('emoji');

        $this->assertDatabaseCount('reactions', 0);
    }

    public function test_a_reaction_outside_the_vocabulary_can_still_be_removed(): void
    {
        $group = $this->group();
        $message = $this->message($group);
        $member = $this->memberOf($group);
        $retired = "\u{1F92F}";
        $message->reactions()->create(['member_id' => $member->getKey(), 'emoji' => $retired]);

        $this->unreact($member, $group, $message, $retired)->assertOk()->assertExactJson(['reactions' => []]);

        $this->assertDatabaseCount('reactions', 0);
    }

    public function test_a_message_from_another_group_is_not_found(): void
    {
        $group = $this->group();
        $elsewhere = $this->group();
        $message = $this->message($elsewhere);
        $member = $this->memberOf($group);

        $this->react($member, $group, $message)->assertNotFound();
        $this->actingAs($member)
            ->getJson("/groups/{$group->getKey()}/talk/messages/{$message->getKey()}/reactions")
            ->assertNotFound();
    }

    public function test_every_reaction_route_goes_with_the_unit(): void
    {
        $group = $this->group();
        $message = $this->message($group);
        $member = $this->memberOf($group);
        $path = "/groups/{$group->getKey()}/talk/messages/{$message->getKey()}/reactions";

        $this->setSnsSetting(SnsSettingKey::FeatureGroupTalkEnabled, false);
        $this->freshRequestState();

        $this->actingAs($member)->postJson($path, ['emoji' => $this->emoji(0)])->assertNotFound();
        $this->actingAs($member)->postJson("{$path}/delete", ['emoji' => $this->emoji(0)])->assertNotFound();
        $this->actingAs($member)->getJson($path)->assertNotFound();
    }

    public function test_a_withdrawing_member_takes_their_reactions_but_not_the_version(): void
    {
        $group = $this->group();
        $message = $this->message($group);
        $member = $this->memberOf($group);

        $this->react($member, $group, $message)->assertOk();
        $version = $this->seq($group);

        $member->delete();

        $this->assertDatabaseCount('reactions', 0);
        $this->assertSame($version, $this->seq($group));
    }

    public function test_deleting_a_message_takes_its_reactions_with_it(): void
    {
        $group = $this->group();
        $author = $this->memberOf($group);
        $message = $this->message($group, $author);

        $this->react($this->memberOf($group), $group, $message)->assertOk();

        $this->actingAs($author)
            ->post("/groups/{$group->getKey()}/talk/messages/{$message->getKey()}/delete")
            ->assertNoContent();

        $this->assertDatabaseCount('group_messages', 0);
        $this->assertDatabaseCount('reactions', 0);
    }

    public function test_deleting_a_group_leaves_no_orphan_reactions(): void
    {
        $group = $this->group();
        $elsewhere = $this->group();
        $kept = $this->message($elsewhere);
        $member = $this->memberOf($group);
        GroupMember::factory()->create(['group_id' => $elsewhere->getKey(), 'member_id' => $member->getKey()]);

        $this->react($member, $group, $this->message($group))->assertOk();
        $this->react($member, $group, $this->message($group), $this->emoji(1))->assertOk();
        $this->react($member, $elsewhere, $kept)->assertOk();

        app(DeleteGroup::class)->purge($group);

        $this->assertDatabaseCount('reactions', 1);
        $this->assertDatabaseHas('reactions', ['reactable_id' => $kept->getKey()]);
    }

    public function test_the_reactor_list_names_who_reacted_with_what(): void
    {
        $group = $this->group();
        $message = $this->message($group);
        $first = $this->memberOf($group);
        $second = $this->memberOf($group);

        $this->react($first, $group, $message, $this->emoji(1))->assertOk();
        $this->react($second, $group, $message, $this->emoji(1))->assertOk();
        $this->react($second, $group, $message, $this->emoji(0))->assertOk();

        $this->actingAs($first)
            ->getJson("/groups/{$group->getKey()}/talk/messages/{$message->getKey()}/reactions")
            ->assertOk()
            // Grouped in the order the emoji first appeared, and the members in the order they reacted.
            ->assertJsonPath('groups.0.emoji', $this->emoji(1))
            ->assertJsonPath('groups.0.count', 2)
            ->assertJsonPath('groups.0.members.0.id', $first->getKey())
            ->assertJsonPath('groups.0.members.1.id', $second->getKey())
            ->assertJsonPath('groups.1.emoji', $this->emoji(0))
            ->assertJsonCount(1, 'groups.1.members');
    }

    public function test_the_reactor_list_caps_the_names_but_not_the_count(): void
    {
        $group = $this->group();
        $message = $this->message($group);
        $total = MessageReactors::PER_EMOJI + 5;
        $reactors = Member::factory()->count($total)->create();

        $at = now();
        Reaction::insert($reactors->map(fn (Member $reactor): array => [
            'reactable_type' => $message->getMorphClass(),
            'reactable_id' => $message->getKey(),
            'member_id' => $reactor->getKey(),
            'emoji' => $this->emoji(0),
            'created_at' => $at,
            'updated_at' => $at,
        ])->all());

        $this->actingAs($this->memberOf($group))
            ->getJson("/groups/{$group->getKey()}/talk/messages/{$message->getKey()}/reactions")
            ->assertOk()
            ->assertJsonPath('groups.0.count', $total)
            ->assertJsonCount(MessageReactors::PER_EMOJI, 'groups.0.members')
            // The cap takes the first to react, not an arbitrary hundred.
            ->assertJsonPath('groups.0.members.0.id', $reactors->first()->getKey());
    }

    /** One grouped read serves a whole page, so each message must still get its own chips. */
    public function test_a_page_gives_every_message_its_own_chips(): void
    {
        $group = $this->group();
        $mine = $this->message($group);
        $theirs = $this->message($group);
        $viewer = $this->memberOf($group);
        $other = $this->memberOf($group);

        $this->react($viewer, $group, $mine, $this->emoji(0))->assertOk();
        $this->react($other, $group, $theirs, $this->emoji(1))->assertOk();

        $page = $this->actingAs($viewer)->getJson("/groups/{$group->getKey()}/talk/messages")->assertOk();

        $page->assertJsonPath('messages.0.reactions', [['emoji' => $this->emoji(0), 'count' => 1, 'mine' => true]]);
        $page->assertJsonPath('messages.1.reactions', [['emoji' => $this->emoji(1), 'count' => 1, 'mine' => false]]);
    }
}
