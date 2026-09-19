<?php

namespace Tests\Feature\Diary\Reactions;

use App\Features\Reactions\Queries\ReactionAggregates;
use App\Features\Reactions\Queries\Reactors;
use App\Models\Diary;
use App\Models\Member;
use App\Models\Reaction;
use App\Support\SnsSettingKey;
use App\Support\Visibility;
use Illuminate\Support\Facades\DB;

class DiaryReactionTest extends DiaryReactionTestCase
{
    public function test_a_guest_is_sent_to_login_on_every_route(): void
    {
        $diary = Diary::factory()->create(['visibility' => Visibility::Open]);
        $comment = $this->comment($diary);

        foreach ([$this->path($diary), $this->path($comment)] as $path) {
            $this->post($path, ['emoji' => $this->emoji(0)])->assertRedirect('/login');
            $this->post("{$path}/delete", ['emoji' => $this->emoji(0)])->assertRedirect('/login');
            $this->get($path)->assertRedirect('/login');
        }
        $this->assertDatabaseCount('reactions', 0);
    }

    public function test_a_member_reacting_writes_a_row_and_is_answered_with_the_count(): void
    {
        $diary = $this->diary();
        $member = Member::factory()->create();

        $this->react($member, $diary)
            ->assertOk()
            ->assertExactJson(['reactions' => [['emoji' => $this->emoji(0), 'count' => 1, 'mine' => true]]]);

        $this->assertDatabaseHas('reactions', [
            'reactable_type' => 'diary',
            'reactable_id' => $diary->getKey(),
            'member_id' => $member->getKey(),
            'emoji' => $this->emoji(0),
        ]);
    }

    public function test_a_comment_takes_its_own_row_under_its_own_alias(): void
    {
        $comment = $this->comment($this->diary());
        $member = Member::factory()->create();

        $this->react($member, $comment)
            ->assertOk()
            ->assertExactJson(['reactions' => [['emoji' => $this->emoji(0), 'count' => 1, 'mine' => true]]]);

        $this->assertDatabaseHas('reactions', ['reactable_type' => 'diaryComment', 'reactable_id' => $comment->getKey()]);
    }

    public function test_a_friends_entry_is_not_found_to_a_non_friend(): void
    {
        $author = Member::factory()->create();
        $diary = Diary::factory()->friends()->create(['member_id' => $author->getKey()]);
        $stranger = Member::factory()->create();
        $friend = Member::factory()->create();
        $this->befriend($author, $friend);

        $this->react($stranger, $diary)->assertNotFound();
        $this->actingAs($stranger)->getJson($this->path($diary))->assertNotFound();
        $this->react($friend, $diary)->assertOk();
    }

    public function test_a_private_entry_takes_only_its_authors_reaction(): void
    {
        $author = Member::factory()->create();
        $diary = Diary::factory()->private()->create(['member_id' => $author->getKey()]);

        $this->react(Member::factory()->create(), $diary)->assertNotFound();
        $this->react($author, $diary)->assertOk();
    }

    public function test_a_blocked_viewer_is_not_found(): void
    {
        $author = Member::factory()->create();
        $diary = $this->diary($author);
        $blocked = Member::factory()->create();
        $this->block($author, $blocked);

        $this->react($blocked, $diary)->assertNotFound();
        $this->actingAs($blocked)->getJson($this->path($diary))->assertNotFound();
        $this->assertDatabaseCount('reactions', 0);
    }

    /** The comment's author is the viewer's friend and the entry's is not, so judging the comment alone would answer 200 where the page answers 404. */
    public function test_a_comment_is_gated_at_its_entry(): void
    {
        $author = Member::factory()->create();
        $diary = Diary::factory()->friends()->create(['member_id' => $author->getKey()]);
        $authorsFriend = Member::factory()->create();
        $this->befriend($author, $authorsFriend);
        $comment = $this->comment($diary, $authorsFriend);
        $viewer = Member::factory()->create();
        $this->befriend($authorsFriend, $viewer);

        $this->actingAs($viewer)->get("/diary/{$diary->getKey()}")->assertNotFound();
        $this->react($viewer, $comment)->assertNotFound();
        $this->actingAs($viewer)->getJson($this->path($comment))->assertNotFound();
        $this->assertDatabaseCount('reactions', 0);

        $this->react($authorsFriend, $comment)->assertOk();
    }

    public function test_someone_elses_reaction_counts_but_is_not_mine(): void
    {
        $diary = $this->diary();
        $reactor = Member::factory()->create();
        $other = Member::factory()->create();

        $this->react($reactor, $diary)->assertOk();

        $this->actingAs($other)
            ->get("/diary/{$diary->getKey()}")
            ->assertInertia(fn ($page) => $page
                ->where('diary.reactions', [['emoji' => $this->emoji(0), 'count' => 1, 'mine' => false]])
                ->where('reactionVocabulary.0', $this->emoji(0)));
    }

    /** The web-public reader has no member row to be `mine`; the counts are still theirs to read. */
    public function test_a_guest_reads_the_chips_on_a_web_public_entry_as_counts(): void
    {
        $diary = Diary::factory()->create(['visibility' => Visibility::Open]);
        $comment = $this->comment($diary);
        $reactor = Member::factory()->create();
        $this->react($reactor, $diary)->assertOk();
        $this->react($reactor, $comment)->assertOk();
        $this->freshRequestState();

        $this->get("/diary/{$diary->getKey()}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('diary.reactions', [['emoji' => $this->emoji(0), 'count' => 1, 'mine' => false]])
                ->where('thread.comments.0.reactions', [['emoji' => $this->emoji(0), 'count' => 1, 'mine' => false]]));
    }

    public function test_the_grouped_read_with_no_viewer_binds_nothing_for_mine(): void
    {
        $diary = $this->diary();
        $this->react(Member::factory()->create(), $diary)->assertOk();
        $bindings = null;
        DB::listen(function ($query) use (&$bindings): void {
            if (preg_match('/from\s+[`"]?reactions[`"]?/i', $query->sql) && str_contains($query->sql, 'group by')) {
                $bindings = $query->bindings;
            }
        });

        $this->assertSame(
            [$diary->getKey() => [['emoji' => $this->emoji(0), 'count' => 1, 'mine' => false]]],
            app(ReactionAggregates::class)(null, Diary::class, [$diary->getKey()]),
        );

        // The type and the id are bound; no member id is.
        $this->assertSame(['diary', $diary->getKey()], $bindings);
    }

    /** The gate answers before the payload is looked at, so an id the viewer may not see reads the same whether the emoji is valid or not. */
    public function test_an_invalid_emoji_on_an_entry_the_viewer_may_not_see_is_still_not_found(): void
    {
        $diary = Diary::factory()->private()->create();
        $comment = $this->comment($diary);
        $stranger = Member::factory()->create();

        $this->react($stranger, $diary, 'not an emoji')->assertNotFound();
        $this->unreact($stranger, $diary, str_repeat('x', 64))->assertNotFound();
        $this->react($stranger, $comment, 'not an emoji')->assertNotFound();
        $this->unreact($stranger, $comment, str_repeat('x', 64))->assertNotFound();
        $this->assertDatabaseCount('reactions', 0);
    }

    public function test_reacting_twice_with_the_same_emoji_changes_nothing(): void
    {
        $diary = $this->diary();
        $member = Member::factory()->create();

        $this->react($member, $diary)->assertOk();
        $this->react($member, $diary)->assertOk()->assertJsonPath('reactions.0.count', 1);

        $this->assertDatabaseCount('reactions', 1);
    }

    public function test_removing_a_reaction_takes_its_row_away(): void
    {
        $comment = $this->comment($this->diary());
        $member = Member::factory()->create();

        $this->react($member, $comment)->assertOk();
        $this->unreact($member, $comment)->assertOk()->assertExactJson(['reactions' => []]);

        $this->assertDatabaseCount('reactions', 0);
    }

    public function test_an_emoji_outside_the_vocabulary_is_refused(): void
    {
        $this->react(Member::factory()->create(), $this->diary(), "\u{1F92F}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('emoji');

        $this->assertDatabaseCount('reactions', 0);
    }

    public function test_a_reaction_outside_the_vocabulary_can_still_be_removed(): void
    {
        $diary = $this->diary();
        $member = Member::factory()->create();
        $retired = "\u{1F92F}";
        $diary->reactions()->create(['member_id' => $member->getKey(), 'emoji' => $retired]);

        $this->unreact($member, $diary, $retired)->assertOk()->assertExactJson(['reactions' => []]);

        $this->assertDatabaseCount('reactions', 0);
    }

    public function test_every_reaction_route_goes_with_the_unit(): void
    {
        $diary = $this->diary();
        $comment = $this->comment($diary);
        $member = Member::factory()->create();

        $this->setSnsSetting(SnsSettingKey::FeatureDiaryEnabled, false);
        $this->freshRequestState();

        foreach ([$this->path($diary), $this->path($comment)] as $path) {
            $this->actingAs($member)->postJson($path, ['emoji' => $this->emoji(0)])->assertNotFound();
            $this->actingAs($member)->postJson("{$path}/delete", ['emoji' => $this->emoji(0)])->assertNotFound();
            $this->actingAs($member)->getJson($path)->assertNotFound();
        }
    }

    public function test_the_reactor_list_names_who_reacted_with_what(): void
    {
        $comment = $this->comment($this->diary());
        $first = Member::factory()->create();
        $second = Member::factory()->create();

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
        $diary = $this->diary();
        $total = Reactors::PER_EMOJI + 5;
        $reactors = Member::factory()->count($total)->create();

        $at = now();
        Reaction::insert($reactors->map(fn (Member $reactor): array => [
            'reactable_type' => $diary->getMorphClass(),
            'reactable_id' => $diary->getKey(),
            'member_id' => $reactor->getKey(),
            'emoji' => $this->emoji(0),
            'created_at' => $at,
            'updated_at' => $at,
        ])->all());

        $this->actingAs(Member::factory()->create())
            ->getJson($this->path($diary))
            ->assertOk()
            ->assertJsonPath('groups.0.count', $total)
            ->assertJsonCount(Reactors::PER_EMOJI, 'groups.0.members');
    }

    /** One grouped read for the entry and one for the page of comments, and each row still gets its own chips. */
    public function test_the_page_gives_the_entry_and_each_comment_their_chips_from_two_grouped_reads(): void
    {
        $viewer = Member::factory()->create();
        $diary = $this->diary();
        $first = $this->comment($diary);
        $second = $this->comment($diary);
        $other = Member::factory()->create();
        $this->react($viewer, $diary, $this->emoji(0))->assertOk();
        $this->react($other, $second, $this->emoji(1))->assertOk();

        $reads = [];
        DB::listen(function ($query) use (&$reads): void {
            if (preg_match('/from\s+[`"]?reactions[`"]?/i', $query->sql)) {
                $reads[] = $query->sql;
            }
        });

        $this->actingAs($viewer)
            ->get("/diary/{$diary->getKey()}")
            ->assertInertia(fn ($page) => $page
                ->where('diary.reactions', [['emoji' => $this->emoji(0), 'count' => 1, 'mine' => true]])
                ->where('thread.comments.0.id', $first->getKey())
                ->where('thread.comments.0.reactions', [])
                ->where('thread.comments.1.reactions', [['emoji' => $this->emoji(1), 'count' => 1, 'mine' => false]]));

        $this->assertCount(2, $reads, 'the page read reactions other than once per shape');
        $this->assertMatchesRegularExpression('/group by/i', $reads[1]);
    }

    public function test_a_withdrawing_reactor_takes_their_reactions(): void
    {
        $diary = $this->diary();
        $member = Member::factory()->create();
        $this->react($member, $diary)->assertOk();

        $member->delete();

        $this->assertDatabaseCount('reactions', 0);
    }
}
