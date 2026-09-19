<?php

namespace Tests\Feature\Group\Reactions;

use App\Features\Reactions\Queries\Reactors;
use App\Models\GroupTopicComment;
use App\Models\Member;
use App\Support\SnsSettingKey;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/** Both boards on every rule: a topic's comment and an event's share one lock and one gate shape. */
class BoardReactionTest extends BoardReactionTestCase
{
    /** @return array<string, array{string}> */
    public static function boards(): array
    {
        return ['topic' => ['topicComment'], 'event' => ['eventComment']];
    }

    #[DataProvider('boards')]
    public function test_a_guest_is_sent_to_login_on_every_route(string $make): void
    {
        $comment = $this->{$make}($this->group());
        $path = $this->path($comment);

        $this->post($path, ['emoji' => $this->emoji(0)])->assertRedirect('/login');
        $this->post("{$path}/delete", ['emoji' => $this->emoji(0)])->assertRedirect('/login');
        $this->get($path)->assertRedirect('/login');
        $this->assertDatabaseCount('reactions', 0);
    }

    #[DataProvider('boards')]
    public function test_a_member_reacting_writes_a_row_under_the_comments_alias(string $make): void
    {
        $group = $this->group();
        $comment = $this->{$make}($group);
        $member = $this->joined($group);

        $this->react($member, $comment)
            ->assertOk()
            ->assertExactJson(['reactions' => [['emoji' => $this->emoji(0), 'count' => 1, 'mine' => true]]]);

        $this->assertDatabaseHas('reactions', [
            'reactable_type' => $comment instanceof GroupTopicComment ? 'groupTopicComment' : 'groupEventComment',
            'reactable_id' => $comment->getKey(),
            'member_id' => $member->getKey(),
        ]);
    }

    /** The board is readable to any member, but reacting is the group's write permission, as commenting is. */
    #[DataProvider('boards')]
    public function test_a_non_member_may_read_the_reactors_but_not_react(string $make): void
    {
        $comment = $this->{$make}($this->group());
        $outsider = Member::factory()->create();

        $this->react($outsider, $comment)->assertNotFound();
        $this->unreact($outsider, $comment)->assertNotFound();
        $this->actingAs($outsider)->getJson($this->path($comment))->assertOk()->assertExactJson(['groups' => []]);
        $this->assertDatabaseCount('reactions', 0);
    }

    #[DataProvider('boards')]
    public function test_a_members_only_board_is_not_found_to_a_non_member_at_all(string $make): void
    {
        $group = $this->group(membersOnly: true);
        $comment = $this->{$make}($group);
        $outsider = Member::factory()->create();

        $this->react($outsider, $comment)->assertNotFound();
        $this->actingAs($outsider)->getJson($this->path($comment))->assertNotFound();
        $this->react($this->joined($group), $comment)->assertOk();
    }

    #[DataProvider('boards')]
    public function test_reacting_twice_with_the_same_emoji_changes_nothing(string $make): void
    {
        $group = $this->group();
        $comment = $this->{$make}($group);
        $member = $this->joined($group);

        $this->react($member, $comment)->assertOk();
        $this->react($member, $comment)->assertOk()->assertJsonPath('reactions.0.count', 1);

        $this->assertDatabaseCount('reactions', 1);
    }

    #[DataProvider('boards')]
    public function test_removing_a_reaction_takes_its_row_away(string $make): void
    {
        $group = $this->group();
        $comment = $this->{$make}($group);
        $member = $this->joined($group);

        $this->react($member, $comment)->assertOk();
        $this->unreact($member, $comment)->assertOk()->assertExactJson(['reactions' => []]);

        $this->assertDatabaseCount('reactions', 0);
    }

    #[DataProvider('boards')]
    public function test_an_emoji_outside_the_vocabulary_is_refused(string $make): void
    {
        $group = $this->group();

        $this->react($this->joined($group), $this->{$make}($group), "\u{1F92F}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('emoji');

        $this->assertDatabaseCount('reactions', 0);
    }

    /** The gate answers before the payload is looked at, so a comment the viewer may not react to reads the same whether the emoji is valid or not. */
    #[DataProvider('boards')]
    public function test_an_invalid_emoji_from_a_non_member_is_still_not_found(string $make): void
    {
        $comment = $this->{$make}($this->group());
        $outsider = Member::factory()->create();

        $this->react($outsider, $comment, 'not an emoji')->assertNotFound();
        $this->unreact($outsider, $comment, str_repeat('x', 64))->assertNotFound();
        $this->assertDatabaseCount('reactions', 0);
    }

    public function test_every_reaction_route_goes_with_its_boards_unit(): void
    {
        $group = $this->group();
        $member = $this->joined($group);
        $topic = $this->topicComment($group);
        $event = $this->eventComment($group);

        $this->setSnsSetting(SnsSettingKey::FeatureGroupTopicEnabled, false);
        $this->freshRequestState();
        $this->react($member, $topic)->assertNotFound();
        $this->actingAs($member)->getJson($this->path($topic))->assertNotFound();
        $this->react($member, $event)->assertOk();

        $this->setSnsSetting(SnsSettingKey::FeatureGroupEventEnabled, false);
        $this->freshRequestState();
        $this->react($member, $event)->assertNotFound();
        $this->actingAs($member)->getJson($this->path($event))->assertNotFound();
    }

    #[DataProvider('boards')]
    public function test_the_reactor_list_names_who_reacted_with_what(string $make): void
    {
        $group = $this->group();
        $comment = $this->{$make}($group);
        $first = $this->joined($group);
        $second = $this->joined($group);

        $this->react($first, $comment, $this->emoji(1))->assertOk();
        $this->react($second, $comment, $this->emoji(1))->assertOk();
        $this->react($second, $comment, $this->emoji(0))->assertOk();

        $this->actingAs($first)
            ->getJson($this->path($comment))
            ->assertOk()
            ->assertJsonPath('groups.0.emoji', $this->emoji(1))
            ->assertJsonPath('groups.0.count', 2)
            ->assertJsonPath('groups.0.members.0.id', $first->getKey())
            ->assertJsonPath('groups.0.members.1.id', $second->getKey())
            ->assertJsonPath('groups.1.emoji', $this->emoji(0))
            ->assertJsonCount(1, 'groups.1.members');
    }

    public function test_the_reactor_list_caps_the_names_but_not_the_count(): void
    {
        $comment = $this->topicComment($this->group());
        $total = Reactors::PER_EMOJI + 5;
        $at = now();
        DB::table('reactions')->insert(Member::factory()->count($total)->create()->map(fn (Member $reactor): array => [
            'reactable_type' => $comment->getMorphClass(),
            'reactable_id' => $comment->getKey(),
            'member_id' => $reactor->getKey(),
            'emoji' => $this->emoji(0),
            'created_at' => $at,
            'updated_at' => $at,
        ])->all());

        $this->actingAs(Member::factory()->create())
            ->getJson($this->path($comment))
            ->assertOk()
            ->assertJsonPath('groups.0.count', $total)
            ->assertJsonCount(Reactors::PER_EMOJI, 'groups.0.members');
    }

    /** One grouped read serves the page of comments, and each row still gets its own chips. */
    public function test_the_topic_page_gives_each_comment_its_chips_from_one_grouped_read(): void
    {
        $group = $this->group();
        $viewer = $this->joined($group);
        $first = $this->topicComment($group);
        $second = GroupTopicComment::factory()->create(['group_topic_id' => $first->group_topic_id, 'member_id' => $viewer->getKey(), 'number' => 2]);
        $this->react($viewer, $second, $this->emoji(1))->assertOk();

        $reads = [];
        DB::listen(function ($query) use (&$reads): void {
            if (preg_match('/from\s+[`"]?reactions[`"]?/i', $query->sql)) {
                $reads[] = $query->sql;
            }
        });

        $this->actingAs($viewer)
            ->get("/topics/{$first->group_topic_id}")
            ->assertInertia(fn ($page) => $page
                ->where('thread.comments.0.reactions', [])
                ->where('thread.comments.1.reactions', [['emoji' => $this->emoji(1), 'count' => 1, 'mine' => true]])
                ->where('reactionVocabulary.0', $this->emoji(0))
                ->has('renderGeneration'));

        $this->assertCount(1, $reads, 'the page read reactions more than once');
        $this->assertMatchesRegularExpression('/group by/i', $reads[0]);
    }

    public function test_the_event_page_gives_each_comment_its_chips(): void
    {
        $group = $this->group();
        $viewer = $this->joined($group);
        $comment = $this->eventComment($group);
        $this->react($viewer, $comment)->assertOk();

        $this->actingAs($viewer)
            ->get("/events/{$comment->group_event_id}")
            ->assertInertia(fn ($page) => $page
                ->where('thread.comments.0.reactions', [['emoji' => $this->emoji(0), 'count' => 1, 'mine' => true]])
                ->where('reactionVocabulary.0', $this->emoji(0)));
    }

    /** A non-member reads the page with the counts and no way to react: `canComment` already says so. */
    public function test_a_non_member_reads_the_chips_on_an_open_board(): void
    {
        $group = $this->group();
        $comment = $this->topicComment($group);
        $this->react($this->joined($group), $comment)->assertOk();

        $this->actingAs(Member::factory()->create())
            ->get("/topics/{$comment->group_topic_id}")
            ->assertInertia(fn ($page) => $page
                ->where('canComment', false)
                ->where('thread.comments.0.reactions', [['emoji' => $this->emoji(0), 'count' => 1, 'mine' => false]]));
    }

    #[DataProvider('boards')]
    public function test_a_withdrawing_reactor_takes_their_reactions(string $make): void
    {
        $group = $this->group();
        $comment = $this->{$make}($group);
        $member = $this->joined($group);
        $this->react($member, $comment)->assertOk();

        $member->delete();

        $this->assertDatabaseCount('reactions', 0);
    }

    /** The comment stays with a null author when its author withdraws, so what others put on it stays too. */
    #[DataProvider('boards')]
    public function test_a_withdrawing_author_leaves_the_reactions_on_their_comment(string $make): void
    {
        $group = $this->group();
        $author = $this->joined($group);
        $comment = $this->{$make}($group, $author);
        $this->react($this->joined($group), $comment)->assertOk();

        $author->delete();

        $this->assertDatabaseHas('reactions', ['reactable_id' => $comment->getKey()]);
        $this->assertDatabaseHas($comment->getTable(), ['id' => $comment->getKey(), 'member_id' => null]);
    }
}
