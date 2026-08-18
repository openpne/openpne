<?php

namespace Tests\Feature\GroupTalk;

use App\Features\GroupTalk\Actions\DeleteGroupMessage;
use App\Features\GroupTopic\TopicReadAccess;
use App\Features\Reactions\ReactionVocabulary;
use App\Models\File;
use App\Models\Group;
use App\Models\GroupMessage;
use App\Models\Member;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;

/**
 * Answering a message: a reference to it, read back off the parent row every time. There is no
 * foreign key behind the column, so a parent that has been deleted leaves the id behind and the
 * answer says as much.
 */
class TalkReplyTest extends TalkTestCase
{
    private function say(Group $group, Member $author, string $body = 'hello', ?GroupMessage $inReplyTo = null): GroupMessage
    {
        return GroupMessage::factory()->create([
            'group_id' => $group->getKey(),
            'member_id' => $author->getKey(),
            'body' => $body,
            'in_reply_to_id' => $inReplyTo?->getKey(),
        ]);
    }

    /** The page as the client reads it, oldest message first. */
    private function page(Member $viewer, Group $group): TestResponse
    {
        return $this->actingAs($viewer)->getJson("/groups/{$group->getKey()}/talk/messages")->assertOk();
    }

    private function pictured(GroupMessage $message): File
    {
        $file = File::factory()->create(['type' => 'image/png']);
        DB::table('group_message_images')->insert([
            'group_message_id' => $message->getKey(),
            'file_id' => $file->getKey(),
            'number' => 1,
        ]);

        return $file;
    }

    public function test_the_composer_writes_the_reference_and_gets_the_answered_message_back(): void
    {
        $group = $this->group();
        $author = $this->memberOf($group);
        $asker = $this->memberOf($group);
        $question = $this->say($group, $asker, 'what is the weather');

        $id = $this->actingAs($author)
            ->postJson("/groups/{$group->getKey()}/talk", [
                'body' => 'rain, probably',
                'reply_to_message_id' => $question->getKey(),
            ])
            ->assertCreated()
            ->assertJsonPath('inReplyTo.deleted', false)
            ->assertJsonPath('inReplyTo.id', $question->getKey())
            ->assertJsonPath('inReplyTo.excerpt', 'what is the weather')
            ->assertJsonPath('inReplyTo.author.id', $asker->getKey())
            ->assertJsonPath('inReplyTo.thumbnailUrl', null)
            ->json('id');

        $this->assertDatabaseHas('group_messages', ['id' => $id, 'in_reply_to_id' => $question->getKey()]);
    }

    /** The reference is what the composer sent, not something inferred: an ordinary message has none. */
    public function test_a_message_that_answers_nothing_carries_no_reference(): void
    {
        $group = $this->group();
        $author = $this->memberOf($group);

        $this->actingAs($author)
            ->postJson("/groups/{$group->getKey()}/talk", ['body' => 'good morning'])
            ->assertCreated()
            ->assertJsonPath('inReplyTo', null);

        $this->assertDatabaseHas('group_messages', ['body' => 'good morning', 'in_reply_to_id' => null]);
    }

    /**
     * The composer keeps the draft and is told why, rather than posting a message that quietly
     * answers nothing. Another group's message is not distinguishable from one that never existed.
     */
    public function test_an_id_that_names_no_live_message_of_this_group_is_refused(): void
    {
        $group = $this->group();
        $elsewhere = $this->group();
        $author = $this->memberOf($group);
        $foreign = $this->say($elsewhere, $this->memberOf($elsewhere), 'elsewhere');
        $deleted = $this->say($group, $this->memberOf($group), 'retracted');
        $deletedId = $deleted->getKey();
        $deleted->delete();

        foreach ([$foreign->getKey(), $deletedId, $deletedId + 9999, 'nonsense', 0] as $id) {
            $this->actingAs($author)
                ->postJson("/groups/{$group->getKey()}/talk", ['body' => 'answered', 'reply_to_message_id' => $id])
                ->assertJsonValidationErrorFor('reply_to_message_id');
        }

        $this->assertDatabaseMissing('group_messages', ['body' => 'answered']);
    }

    /**
     * The reply id is resolved against the group, so a non-member reaching that resolve would learn a
     * message's existence from the 422 an unknown id draws, told apart from the 404 a live one draws.
     * Posting is gated first: every id is one 404, whatever it names. The teeth — remove the gate in
     * store() and the two answers split, which is the oracle this closes.
     */
    public function test_a_non_member_cannot_probe_message_existence_through_the_reply_id(): void
    {
        $group = $this->group(TopicReadAccess::MembersOnly);
        $member = $this->memberOf($group);
        $live = $this->say($group, $member, 'members only');

        $outsider = Member::factory()->create();

        // A live message of the group, and an id that is not one, answer a non-member identically.
        $this->actingAs($outsider)
            ->postJson("/groups/{$group->getKey()}/talk", ['body' => 'let me in', 'reply_to_message_id' => $live->getKey()])
            ->assertNotFound();
        $this->actingAs($outsider)
            ->postJson("/groups/{$group->getKey()}/talk", ['body' => 'let me in', 'reply_to_message_id' => $live->getKey() + 9999])
            ->assertNotFound();

        $this->assertDatabaseMissing('group_messages', ['body' => 'let me in']);
    }

    /**
     * The teeth of the foreign-key drop. `nullOnDelete` would clear the column here, and the answer
     * would read as one that never answered anything — the state this feature has to tell apart from
     * a parent that is gone. Reverting the drop migration restores the self-FK on both engines, and
     * the SET NULL it brings back turns this red.
     */
    public function test_deleting_the_answered_message_leaves_the_reference_behind_and_it_reads_as_deleted(): void
    {
        $group = $this->group();
        $viewer = $this->memberOf($group);
        $asker = $this->memberOf($group);
        $question = $this->say($group, $asker, 'what is the weather');
        $answer = $this->say($group, $viewer, 'rain, probably', inReplyTo: $question);
        $questionId = $question->getKey();

        app(DeleteGroupMessage::class)($asker, $question);

        $this->assertSame($questionId, (int) $answer->fresh()->in_reply_to_id);

        $this->page($viewer, $group)
            ->assertJsonCount(1, 'messages')
            ->assertJsonPath('messages.0.id', $answer->getKey())
            ->assertJsonPath('messages.0.inReplyTo', ['deleted' => true]);
    }

    /** Nothing else may produce a dangling reference, and the schema is what holds that. */
    public function test_the_column_carries_no_foreign_key_and_the_conversation_index_survived_the_rebuild(): void
    {
        $keys = collect(Schema::getForeignKeys('group_messages'))->pluck('columns');
        $indexes = collect(Schema::getIndexes('group_messages'))->pluck('columns');

        $this->assertNotContains(['in_reply_to_id'], $keys->all());
        // The engines disagree about how member_id's key is named; that both survive is what says the
        // SQLite table rebuild kept the rest of the definition.
        $this->assertContains(['group_id'], $keys->all());
        $this->assertContains(['member_id'], $keys->all());
        $this->assertContains(['group_id', 'created_at', 'id'], $indexes->all());
    }

    /**
     * An id from another conversation reads as deleted rather than reaching across: the parent lookup
     * is bound to the group, so nothing about a foreign room can be read off a reply.
     */
    public function test_a_reference_into_another_group_reads_as_deleted(): void
    {
        $group = $this->group();
        $elsewhere = $this->group();
        $viewer = $this->memberOf($group);
        $foreign = $this->say($elsewhere, $this->memberOf($elsewhere), 'a secret');
        $this->say($group, $viewer, 'answered', inReplyTo: $foreign);

        $this->page($viewer, $group)
            ->assertJsonPath('messages.0.inReplyTo', ['deleted' => true])
            ->assertDontSee('a secret');
    }

    /** The excerpt is the line every list previews a message by, so a body cannot grow the header. */
    public function test_the_excerpt_flattens_a_multi_line_body_and_keeps_a_body_of_zero(): void
    {
        $group = $this->group();
        $viewer = $this->memberOf($group);
        $long = $this->say($group, $viewer, "one\ntwo\n\nthree");
        $zero = $this->say($group, $viewer, '0');
        $this->say($group, $viewer, 'answering the first', inReplyTo: $long);
        $this->say($group, $viewer, 'answering the second', inReplyTo: $zero);

        $this->page($viewer, $group)
            ->assertJsonPath('messages.2.inReplyTo.excerpt', 'one two three')
            ->assertJsonPath('messages.3.inReplyTo.excerpt', '0');
    }

    /**
     * The excerpt is bounded, not only flattened: a wall of text cannot grow the header. The bound is
     * ChatPreview's, shared with every other list — pinned here so a change to it is noticed on the
     * reply header too.
     */
    public function test_a_long_body_is_bounded_to_the_shared_preview_length(): void
    {
        $group = $this->group();
        $viewer = $this->memberOf($group);
        $wall = $this->say($group, $viewer, str_repeat('x', 400));
        $this->say($group, $viewer, 'answering', inReplyTo: $wall);

        $excerpt = $this->page($viewer, $group)->json('messages.1.inReplyTo.excerpt');

        $this->assertSame(140, mb_strlen($excerpt));
        $this->assertSame(str_repeat('x', 140), $excerpt);
    }

    public function test_a_picture_only_parent_reads_as_a_picture_and_carries_its_thumbnail(): void
    {
        $group = $this->group();
        $viewer = $this->memberOf($group);
        $picture = $this->say($group, $viewer, '');
        $file = $this->pictured($picture);
        $this->say($group, $viewer, 'nice one', inReplyTo: $picture);

        $this->page($viewer, $group)
            ->assertJsonPath('messages.1.inReplyTo.excerpt', __('Image'))
            ->assertJsonPath('messages.1.inReplyTo.thumbnailUrl', $file->thumbnailUrl(120, 120, square: true));
    }

    /** The same absence the message's own byline reads as: there is no account left to name. */
    public function test_a_parent_whose_author_has_withdrawn_reads_as_no_author(): void
    {
        $group = $this->group();
        $viewer = $this->memberOf($group);
        $gone = GroupMessage::factory()->withdrawnAuthor()->create(['group_id' => $group->getKey(), 'body' => 'asked']);
        $this->say($group, $viewer, 'answered', inReplyTo: $gone);

        $this->page($viewer, $group)
            ->assertJsonPath('messages.1.inReplyTo.deleted', false)
            ->assertJsonPath('messages.1.inReplyTo.excerpt', 'asked')
            ->assertJsonPath('messages.1.inReplyTo.author', null);
    }

    /**
     * One level, always. An answer describes what it answers; whatever that message answered in turn
     * is a place in the conversation, reachable by its cursor rather than by nesting.
     */
    public function test_an_answer_to_an_answer_describes_only_its_own_parent(): void
    {
        $group = $this->group();
        $viewer = $this->memberOf($group);
        $first = $this->say($group, $viewer, 'the question');
        $second = $this->say($group, $viewer, 'the answer', inReplyTo: $first);
        $this->say($group, $viewer, 'the follow-up', inReplyTo: $second);

        $reference = $this->page($viewer, $group)->json('messages.2.inReplyTo');

        $this->assertSame($second->getKey(), $reference['id']);
        $this->assertSame('the answer', $reference['excerpt']);
        $this->assertSame(['deleted', 'id', 'cursor', 'author', 'excerpt', 'thumbnailUrl'], array_keys($reference));
    }

    /** The cursor is the parent's own position, which is what a jump to it is asked for with. */
    public function test_the_reference_carries_the_cursor_the_page_around_it_is_read_with(): void
    {
        $group = $this->group();
        $viewer = $this->memberOf($group);
        $question = $this->say($group, $viewer, 'the question');
        $this->say($group, $viewer, 'the answer', inReplyTo: $question);

        $cursor = $this->page($viewer, $group)->json('messages.1.inReplyTo.cursor');

        $this->assertSame($this->page($viewer, $group)->json('messages.0.cursor'), $cursor);
        $this->actingAs($viewer)
            ->getJson("/groups/{$group->getKey()}/talk/messages?context=".urlencode($cursor))
            ->assertOk()
            ->assertJsonPath('messages.0.id', $question->getKey());
    }

    /**
     * A touched row replaces the client's whole row, so it has to arrive with everything a page's row
     * carries — otherwise a reply loses its header the moment somebody reacts to it.
     */
    public function test_a_row_re_serialized_for_a_reaction_still_carries_its_reference(): void
    {
        $group = $this->group();
        $viewer = $this->memberOf($group);
        $question = $this->say($group, $viewer, 'the question');
        $answer = $this->say($group, $this->memberOf($group), 'the answer', inReplyTo: $question);

        $this->actingAs($viewer)->postJson(
            "/groups/{$group->getKey()}/talk/messages/{$answer->getKey()}/reactions",
            ['emoji' => ReactionVocabulary::all()[0]],
        )->assertOk();

        $this->actingAs($viewer)
            ->getJson("/groups/{$group->getKey()}/talk/messages?reactionsAfter=0")
            ->assertOk()
            ->assertJsonPath('touched.0.id', $answer->getKey())
            ->assertJsonPath('touched.0.inReplyTo.deleted', false)
            ->assertJsonPath('touched.0.inReplyTo.id', $question->getKey());
    }

    /** The rendered page answers with the same shape the JSON reads do. */
    public function test_the_rendered_page_ships_the_reference(): void
    {
        $group = $this->group();
        $viewer = $this->memberOf($group);
        $question = $this->say($group, $viewer, 'the question');
        $this->say($group, $viewer, 'the answer', inReplyTo: $question);

        $this->actingAs($viewer)
            ->get("/groups/{$group->getKey()}/talk")
            ->assertInertia(fn ($page) => $page
                ->where('page.messages.0.inReplyTo', null)
                ->where('page.messages.1.inReplyTo.id', $question->getKey())
                ->where('page.messages.1.inReplyTo.excerpt', 'the question'));
    }

    /** The parents of a page are one batched read, so the cost is per page rather than per reply. */
    public function test_a_page_costs_the_same_whether_it_holds_one_reply_or_ten(): void
    {
        $this->assertSame(
            $this->queryCount(...$this->conversation(1)),
            $this->queryCount(...$this->conversation(10)),
            'the page grew a query per reply',
        );
    }

    /**
     * A room holding $replies answers, each to a message of its own.
     *
     * @return array{0: Member, 1: Group}
     */
    private function conversation(int $replies): array
    {
        $group = $this->group();
        $viewer = $this->memberOf($group);

        foreach (range(1, $replies) as $n) {
            $this->say($group, $viewer, "answer {$n}", inReplyTo: $this->say($group, $viewer, "question {$n}"));
        }

        return [$viewer, $group];
    }

    private function queryCount(Member $viewer, Group $group): int
    {
        $this->page($viewer, $group); // the site settings and terms are read once per process
        DB::flushQueryLog(); // the log survives disableQueryLog(), so a second call would stack
        DB::enableQueryLog();
        $this->page($viewer, $group);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
