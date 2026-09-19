<?php

namespace Tests\Feature\Timeline\Reactions;

use App\Features\Reactions\Queries\Reactors;
use App\Models\Member;
use App\Models\Reaction;
use App\Models\TimelinePost;
use App\Support\SnsSettingKey;
use Illuminate\Support\Facades\DB;

class TimelineReactionTest extends TimelineReactionTestCase
{
    public function test_a_guest_is_sent_to_login_on_every_route(): void
    {
        $post = $this->rootPost();
        $path = "/timeline/{$post->getKey()}/reactions";

        $this->post($path, ['emoji' => $this->emoji(0)])->assertRedirect('/login');
        $this->post("{$path}/delete", ['emoji' => $this->emoji(0)])->assertRedirect('/login');
        $this->get($path)->assertRedirect('/login');
        $this->assertDatabaseCount('reactions', 0);
    }

    public function test_a_member_reacting_writes_a_row_and_is_answered_with_the_count(): void
    {
        $post = $this->rootPost();
        $member = Member::factory()->create();

        $this->react($member, $post)
            ->assertOk()
            ->assertExactJson(['reactions' => [['emoji' => $this->emoji(0), 'count' => 1, 'mine' => true]]]);

        $this->assertDatabaseHas('reactions', [
            'reactable_type' => 'timelinePost',
            'reactable_id' => $post->getKey(),
            'member_id' => $member->getKey(),
            'emoji' => $this->emoji(0),
        ]);
    }

    public function test_a_friends_post_is_not_found_to_a_non_friend(): void
    {
        $author = Member::factory()->create();
        $post = TimelinePost::factory()->friends()->create(['member_id' => $author->getKey()]);
        $stranger = Member::factory()->create();
        $friend = Member::factory()->create();
        $this->befriend($author, $friend);

        $this->react($stranger, $post)->assertNotFound();
        $this->actingAs($stranger)->getJson("/timeline/{$post->getKey()}/reactions")->assertNotFound();
        $this->react($friend, $post)->assertOk();
    }

    public function test_a_private_post_takes_only_its_authors_reaction(): void
    {
        $author = Member::factory()->create();
        $post = TimelinePost::factory()->private()->create(['member_id' => $author->getKey()]);

        $this->react(Member::factory()->create(), $post)->assertNotFound();
        $this->react($author, $post)->assertOk();
    }

    public function test_a_blocked_viewer_is_not_found(): void
    {
        $author = Member::factory()->create();
        $post = $this->rootPost($author);
        $blocked = Member::factory()->create();
        $this->block($author, $blocked);

        $this->react($blocked, $post)->assertNotFound();
        $this->actingAs($blocked)->getJson("/timeline/{$post->getKey()}/reactions")->assertNotFound();
        $this->assertDatabaseCount('reactions', 0);
    }

    /** The thread is one audience: the reply's own author is not who the clearance is read against. */
    public function test_a_reply_is_gated_at_its_root(): void
    {
        $author = Member::factory()->create();
        $root = TimelinePost::factory()->friends()->create(['member_id' => $author->getKey()]);
        $friend = Member::factory()->create();
        $this->befriend($author, $friend);
        $reply = $this->reply($root, $friend);
        $stranger = Member::factory()->create();

        $this->react($stranger, $reply)->assertNotFound();
        $this->react($friend, $reply)->assertOk();
        $this->assertDatabaseHas('reactions', ['reactable_id' => $reply->getKey()]);
    }

    public function test_reacting_is_not_posting(): void
    {
        $post = $this->rootPost();
        $this->setSnsSetting(SnsSettingKey::TimelinePostingEnabled, false);
        $this->freshRequestState();

        $this->react(Member::factory()->create(), $post)->assertOk();
    }

    public function test_someone_elses_reaction_counts_but_is_not_mine(): void
    {
        $post = $this->rootPost();
        $reactor = Member::factory()->create();
        $other = Member::factory()->create();

        $this->react($reactor, $post)->assertOk();

        $this->actingAs($other)
            ->get("/timeline/{$post->getKey()}")
            ->assertInertia(fn ($page) => $page
                ->where('post.reactions', [['emoji' => $this->emoji(0), 'count' => 1, 'mine' => false]])
                ->where('reactionVocabulary.0', $this->emoji(0)));
    }

    public function test_reacting_twice_with_the_same_emoji_changes_nothing(): void
    {
        $post = $this->rootPost();
        $member = Member::factory()->create();

        $this->react($member, $post)->assertOk();
        $this->react($member, $post)->assertOk()->assertJsonPath('reactions.0.count', 1);

        $this->assertDatabaseCount('reactions', 1);
    }

    public function test_a_second_emoji_from_the_same_member_is_an_addition(): void
    {
        $post = $this->rootPost();
        $member = Member::factory()->create();

        $this->react($member, $post, $this->emoji(0))->assertOk();
        $this->react($member, $post, $this->emoji(1))
            ->assertOk()
            ->assertExactJson(['reactions' => [
                ['emoji' => $this->emoji(0), 'count' => 1, 'mine' => true],
                ['emoji' => $this->emoji(1), 'count' => 1, 'mine' => true],
            ]]);
    }

    public function test_removing_a_reaction_takes_its_row_away(): void
    {
        $post = $this->rootPost();
        $member = Member::factory()->create();

        $this->react($member, $post)->assertOk();
        $this->unreact($member, $post)->assertOk()->assertExactJson(['reactions' => []]);

        $this->assertDatabaseCount('reactions', 0);
    }

    public function test_removing_one_that_is_not_there_changes_nothing(): void
    {
        $this->unreact(Member::factory()->create(), $this->rootPost())->assertOk()->assertExactJson(['reactions' => []]);
    }

    public function test_an_emoji_outside_the_vocabulary_is_refused(): void
    {
        $this->react(Member::factory()->create(), $this->rootPost(), "\u{1F92F}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('emoji');

        $this->assertDatabaseCount('reactions', 0);
    }

    public function test_a_reaction_outside_the_vocabulary_can_still_be_removed(): void
    {
        $post = $this->rootPost();
        $member = Member::factory()->create();
        $retired = "\u{1F92F}";
        $post->reactions()->create(['member_id' => $member->getKey(), 'emoji' => $retired]);

        $this->unreact($member, $post, $retired)->assertOk()->assertExactJson(['reactions' => []]);

        $this->assertDatabaseCount('reactions', 0);
    }

    public function test_every_reaction_route_goes_with_the_unit(): void
    {
        $post = $this->rootPost();
        $member = Member::factory()->create();
        $path = "/timeline/{$post->getKey()}/reactions";

        $this->setSnsSetting(SnsSettingKey::FeatureTimelineEnabled, false);
        $this->freshRequestState();

        $this->actingAs($member)->postJson($path, ['emoji' => $this->emoji(0)])->assertNotFound();
        $this->actingAs($member)->postJson("{$path}/delete", ['emoji' => $this->emoji(0)])->assertNotFound();
        $this->actingAs($member)->getJson($path)->assertNotFound();
    }

    public function test_the_reactor_list_names_who_reacted_with_what(): void
    {
        $post = $this->rootPost();
        $first = Member::factory()->create();
        $second = Member::factory()->create();

        $this->react($first, $post, $this->emoji(1))->assertOk();
        $this->react($second, $post, $this->emoji(1))->assertOk();
        $this->react($second, $post, $this->emoji(0))->assertOk();

        $this->actingAs($first)
            ->getJson("/timeline/{$post->getKey()}/reactions")
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
        $post = $this->rootPost();
        $total = Reactors::PER_EMOJI + 5;
        $reactors = Member::factory()->count($total)->create();

        $at = now();
        Reaction::insert($reactors->map(fn (Member $reactor): array => [
            'reactable_type' => $post->getMorphClass(),
            'reactable_id' => $post->getKey(),
            'member_id' => $reactor->getKey(),
            'emoji' => $this->emoji(0),
            'created_at' => $at,
            'updated_at' => $at,
        ])->all());

        $this->actingAs(Member::factory()->create())
            ->getJson("/timeline/{$post->getKey()}/reactions")
            ->assertOk()
            ->assertJsonPath('groups.0.count', $total)
            ->assertJsonCount(Reactors::PER_EMOJI, 'groups.0.members');
    }

    /** One grouped read serves the whole page, and each row still gets its own chips. */
    public function test_a_feed_page_gives_every_post_its_own_chips_from_one_grouped_read(): void
    {
        $viewer = Member::factory()->create();
        $mine = $this->rootPost($viewer);
        $theirs = $this->rootPost();
        $other = Member::factory()->create();
        $this->react($viewer, $mine, $this->emoji(0))->assertOk();
        $this->react($other, $theirs, $this->emoji(1))->assertOk();

        $reads = [];
        DB::listen(function ($query) use (&$reads): void {
            if (preg_match('/from\s+[`"]?reactions[`"]?/i', $query->sql)) {
                $reads[] = $query->sql;
            }
        });

        $this->actingAs($viewer)
            ->get('/timeline')
            ->assertInertia(fn ($page) => $page
                ->where('posts.data.0.reactions', [['emoji' => $this->emoji(1), 'count' => 1, 'mine' => false]])
                ->where('posts.data.1.reactions', [['emoji' => $this->emoji(0), 'count' => 1, 'mine' => true]]));

        $this->assertCount(1, $reads, 'the feed read reactions more than once');
        $this->assertMatchesRegularExpression('/group by/i', $reads[0]);
    }

    public function test_the_thread_page_gives_the_replies_their_chips(): void
    {
        $root = $this->rootPost();
        $reply = $this->reply($root);
        $viewer = Member::factory()->create();
        $this->react($viewer, $reply)->assertOk();

        $this->actingAs($viewer)
            ->get("/timeline/{$root->getKey()}")
            ->assertInertia(fn ($page) => $page
                ->where('post.reactions', [])
                ->where('replies.0.reactions', [['emoji' => $this->emoji(0), 'count' => 1, 'mine' => true]]));
    }

    public function test_the_dashboard_digest_carries_the_chips(): void
    {
        $post = $this->rootPost();
        $viewer = Member::factory()->create();
        $this->react($viewer, $post)->assertOk();

        $this->actingAs($viewer)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->where('timeline.0.reactions', [['emoji' => $this->emoji(0), 'count' => 1, 'mine' => true]]));
    }

    public function test_a_withdrawing_member_takes_their_reactions(): void
    {
        $post = $this->rootPost();
        $member = Member::factory()->create();
        $this->react($member, $post)->assertOk();

        $member->delete();

        $this->assertDatabaseCount('reactions', 0);
    }
}
