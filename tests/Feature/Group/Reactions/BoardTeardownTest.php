<?php

namespace Tests\Feature\Group\Reactions;

use App\Features\Group\Actions\DeleteGroup;
use App\Features\Group\BoardCommentReactionSurface;
use App\Features\GroupEvent\Actions\CreateEvent;
use App\Features\GroupEvent\Actions\CreateEventComment;
use App\Features\GroupEvent\Actions\DeleteEvent;
use App\Features\GroupEvent\Actions\DeleteEventComment;
use App\Features\GroupEvent\Data\GroupEventFormData;
use App\Features\GroupTopic\Actions\CreateTopic;
use App\Features\GroupTopic\Actions\CreateTopicComment;
use App\Features\GroupTopic\Actions\DeleteTopic;
use App\Features\GroupTopic\Actions\DeleteTopicComment;
use App\Features\GroupTopic\Data\GroupTopicFormData;
use App\Features\Reactions\Actions\AddReaction;
use App\Features\Reactions\Exceptions\ReactionRefused;
use App\Features\Reactions\ReactionVocabulary;
use App\Files\FileStorage;
use App\Models\File;
use App\Models\Group;
use App\Models\GroupTopic;
use App\Models\GroupTopicComment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Single-connection: the rows and bytes each delete reclaims, and the transaction they are reclaimed
 * in. What the locks add is BoardReactionLockOrderTest's, on MySQL.
 */
class BoardTeardownTest extends BoardReactionTestCase
{
    public function test_deleting_a_comment_sweeps_its_reactions_inside_the_transaction(): void
    {
        $group = $this->group();
        $comment = $this->topicComment($group);
        $kept = $this->eventComment($group);
        $reactor = $this->joined($group);
        $this->react($reactor, $comment)->assertOk();
        $this->react($reactor, $kept)->assertOk();
        $levels = $this->sweepLevels();

        app(DeleteTopicComment::class)->purge($comment);

        $this->assertSame([DB::transactionLevel() + 1], $levels());
        $this->assertDatabaseMissing('reactions', ['reactable_id' => $comment->getKey(), 'reactable_type' => 'groupTopicComment']);
        $this->assertDatabaseHas('reactions', ['reactable_id' => $kept->getKey(), 'reactable_type' => 'groupEventComment']);
    }

    public function test_deleting_an_event_comment_sweeps_its_reactions(): void
    {
        $group = $this->group();
        $comment = $this->eventComment($group);
        $this->react($this->joined($group), $comment)->assertOk();

        app(DeleteEventComment::class)->purge($comment);

        $this->assertDatabaseCount('reactions', 0);
    }

    public function test_deleting_a_topic_sweeps_its_comments_reactions_and_purges_the_bytes(): void
    {
        $group = $this->group();
        $author = $this->joined($group);
        $topic = app(CreateTopic::class)($author, $group, new GroupTopicFormData('Topic', 'Body'), [UploadedFile::fake()->image('t.png', 20, 20)]);
        $comment = app(CreateTopicComment::class)($author, $topic, 'reply', [UploadedFile::fake()->image('c.png', 20, 20)]);
        $this->react($author, $comment)->assertOk();
        $files = [$topic->images()->with('file')->firstOrFail()->file, $comment->images()->with('file')->firstOrFail()->file];
        $levels = $this->sweepLevels();

        app(DeleteTopic::class)->purge($topic->fresh());

        $this->assertSame([DB::transactionLevel() + 1], $levels());
        $this->assertDatabaseCount('reactions', 0);
        $this->assertBytesGone($files);
    }

    public function test_deleting_an_event_sweeps_its_comments_reactions_and_purges_the_bytes(): void
    {
        $group = $this->group();
        $author = $this->joined($group);
        $event = app(CreateEvent::class)($author, $group, $this->eventForm(), [UploadedFile::fake()->image('e.png', 20, 20)]);
        $comment = app(CreateEventComment::class)($author, $event, 'reply', [UploadedFile::fake()->image('c.png', 20, 20)]);
        $this->react($author, $comment)->assertOk();
        $files = [$event->images()->with('file')->firstOrFail()->file, $comment->images()->with('file')->firstOrFail()->file];

        app(DeleteEvent::class)->purge($event->fresh());

        $this->assertDatabaseCount('reactions', 0);
        $this->assertBytesGone($files);
    }

    /** The first pin of the group teardown's board arm: every board row's reactions and bytes go, in one transaction under the group row. */
    public function test_tearing_a_group_down_sweeps_the_boards_reactions_and_purges_their_bytes(): void
    {
        $group = $this->group();
        $author = $this->joined($group);
        $topic = app(CreateTopic::class)($author, $group, new GroupTopicFormData('Topic', 'Body'), [UploadedFile::fake()->image('t.png', 20, 20)]);
        $topicComment = app(CreateTopicComment::class)($author, $topic, 'reply', [UploadedFile::fake()->image('tc.png', 20, 20)]);
        $event = app(CreateEvent::class)($author, $group, $this->eventForm(), [UploadedFile::fake()->image('e.png', 20, 20)]);
        $eventComment = app(CreateEventComment::class)($author, $event, 'reply', [UploadedFile::fake()->image('ec.png', 20, 20)]);
        $this->react($author, $topicComment)->assertOk();
        $this->react($author, $eventComment)->assertOk();
        $otherGroup = $this->group();
        $otherGroupsComment = $this->topicComment($otherGroup);
        $this->react($this->joined($otherGroup), $otherGroupsComment)->assertOk();
        $files = [
            $topic->images()->with('file')->firstOrFail()->file,
            $topicComment->images()->with('file')->firstOrFail()->file,
            $event->images()->with('file')->firstOrFail()->file,
            $eventComment->images()->with('file')->firstOrFail()->file,
        ];
        $levels = $this->sweepLevels();

        app(DeleteGroup::class)->purge($group);

        // One delete per alias that had rows (the talk had none), each inside the one transaction.
        $this->assertSame([DB::transactionLevel() + 1, DB::transactionLevel() + 1], $levels());
        $this->assertDatabaseMissing('groups', ['id' => $group->getKey()]);
        $this->assertDatabaseCount('group_topics', 1);
        $this->assertDatabaseCount('reactions', 1);
        $this->assertDatabaseHas('reactions', ['reactable_id' => $otherGroupsComment->getKey()]);
        $this->assertBytesGone($files);
    }

    /** The placeholders do not grow with the group: MySQL caps a prepared statement at 65,535, and a decade-old board's comments pass that. */
    public function test_the_teardown_reaches_every_row_by_subquery_and_binds_nothing_but_the_group_id(): void
    {
        $this->group(); // so the group's id differs from its first topic's and event's
        $group = $this->group();
        $author = $this->joined($group);
        $topic = app(CreateTopic::class)($author, $group, new GroupTopicFormData('Topic', 'Body'), [UploadedFile::fake()->image('t.png', 20, 20)]);
        $event = app(CreateEvent::class)($author, $group, $this->eventForm(), [UploadedFile::fake()->image('e.png', 20, 20)]);
        foreach (range(1, 3) as $i) {
            $this->react($author, app(CreateTopicComment::class)($author, $topic, "reply {$i}", []))->assertOk();
        }
        $this->react($author, app(CreateEventComment::class)($author, $event, 'reply', []))->assertOk();
        $bindings = [];
        DB::listen(function ($query) use (&$bindings): void {
            if (preg_match('/^select .* from [`"]files[`"] where [`"]id[`"] in \(select|^delete from [`"]reactions[`"]/', $query->sql)) {
                $bindings[] = $query->bindings;
            }
        });

        app(DeleteGroup::class)->purge($group);

        $this->assertCount(3, $bindings, 'one File collection and one chunked reaction delete per board');
        $this->assertSame([$group->getKey()], array_values(array_unique($bindings[0])), 'the File collection binds the group id, however many times, and nothing else');
        $this->assertCount(3, $bindings[1], 'the topic reaction delete binds the reactions\' own ids');
        $this->assertCount(1, $bindings[2], 'the event reaction delete binds the reactions\' own ids');
        $this->assertDatabaseCount('reactions', 0);
    }

    /** The chunk is the other half of the placeholder cap: past it the sweep must issue another statement, not a bigger one. */
    public function test_the_reaction_sweep_deletes_in_chunks_past_the_chunk_size(): void
    {
        $group = $this->group();
        $author = $this->joined($group);
        $topic = GroupTopic::factory()->create(['group_id' => $group->getKey(), 'member_id' => $author->getKey()]);
        $comments = GroupTopicComment::factory()->count(126)->create(['group_topic_id' => $topic->getKey(), 'member_id' => $author->getKey()]);
        $at = now();
        $rows = [];
        foreach ($comments as $comment) {
            foreach (ReactionVocabulary::all() as $emoji) {
                $rows[] = ['reactable_type' => $comment->getMorphClass(), 'reactable_id' => $comment->getKey(), 'member_id' => $author->getKey(), 'emoji' => $emoji, 'created_at' => $at, 'updated_at' => $at];
            }
        }
        DB::table('reactions')->insert($rows);
        $this->assertGreaterThan(1000, count($rows));
        $deletes = [];
        DB::listen(function ($query) use (&$deletes): void {
            if (preg_match('/^delete from [`"]reactions[`"]/', $query->sql)) {
                $deletes[] = count($query->bindings);
            }
        });

        app(DeleteGroup::class)->purge($group);

        $this->assertSame(array_map('count', array_chunk(range(1, count($rows)), 1000)), $deletes);
        $this->assertDatabaseCount('reactions', 0);
    }

    /** The comments are paged too, so what PHP holds is one page: a group past the page size needs a second content read. */
    public function test_the_reaction_sweep_pages_the_comments_past_the_page_size(): void
    {
        $group = $this->group();
        $author = $this->joined($group);
        $topic = GroupTopic::factory()->create(['group_id' => $group->getKey(), 'member_id' => $author->getKey()]);
        $at = now();
        DB::table('group_topic_comments')->insert(array_map(fn (int $i): array => [
            'group_topic_id' => $topic->getKey(), 'member_id' => $author->getKey(), 'number' => $i, 'body' => 'B', 'created_at' => $at, 'updated_at' => $at,
        ], range(1, 1001)));
        $commentIds = DB::table('group_topic_comments')->where('group_topic_id', $topic->getKey())->pluck('id')->all();
        DB::table('reactions')->insert(array_map(fn (int $id): array => [
            'reactable_type' => 'groupTopicComment', 'reactable_id' => $id, 'member_id' => $author->getKey(), 'emoji' => $this->emoji(0), 'created_at' => $at, 'updated_at' => $at,
        ], $commentIds));
        $pages = 0;
        $deletes = [];
        DB::listen(function ($query) use (&$pages, &$deletes): void {
            if (preg_match('/from [`"]group_topic_comments[`"] where .*order by [`"]number[`"] asc, [`"]id[`"] asc limit 1000$/', $query->sql)) {
                $pages++;
            }
            if (preg_match('/^delete from [`"]reactions[`"]/', $query->sql)) {
                $deletes[] = count($query->bindings);
            }
        });

        app(DeleteGroup::class)->purge($group);

        $this->assertSame(2, $pages, 'a thousand and one comments are two pages');
        $this->assertSame([1000, 1], $deletes);
        $this->assertDatabaseCount('reactions', 0);
    }

    /** The room is paged in its own index's order, (created_at, id) under the group, so no page sorts or scans past the room. */
    public function test_the_talk_sweep_pages_the_messages_in_the_room_index_order(): void
    {
        $group = $this->group();
        $author = $this->joined($group);
        $at = now();
        DB::table('group_messages')->insert(array_map(fn (int $i): array => [
            'group_id' => $group->getKey(), 'member_id' => $author->getKey(), 'in_reply_to_id' => null, 'body' => "m{$i}", 'created_at' => $at, 'updated_at' => $at,
        ], range(1, 1001)));
        $messageIds = DB::table('group_messages')->where('group_id', $group->getKey())->orderBy('id')->pluck('id')->all();
        DB::table('reactions')->insert(array_map(fn (int $id): array => [
            'reactable_type' => 'groupMessage', 'reactable_id' => $id, 'member_id' => $author->getKey(), 'emoji' => $this->emoji(0), 'created_at' => $at, 'updated_at' => $at,
        ], $messageIds));
        $pages = [];
        DB::listen(function ($query) use (&$pages): void {
            if (preg_match('/from [`"]group_messages[`"] where .*order by [`"]created_at[`"] asc, [`"]id[`"] asc limit 1000$/', $query->sql)) {
                $pages[] = $query->bindings;
            }
        });

        app(DeleteGroup::class)->purge($group);

        // Two pages: the first carries no cursor, the second starts after its last (created_at, id), all one timestamp here.
        $this->assertCount(2, $pages);
        $this->assertCount(1, $pages[0]);
        $this->assertSame($messageIds[999], $pages[1][2], 'the cursor is the last row of the page');
        $this->assertDatabaseCount('reactions', 0);
    }

    /** A timestamp the transfer copied as null sorts before any cursor; the first page carries none, so it is still swept. */
    public function test_the_talk_sweep_reaches_a_message_whose_timestamp_sorts_before_any_cursor(): void
    {
        $group = $this->group();
        $author = $this->joined($group);
        $at = now();
        $rows = array_map(fn (int $i): array => [
            'group_id' => $group->getKey(), 'member_id' => $author->getKey(), 'in_reply_to_id' => null, 'body' => "m{$i}", 'created_at' => $at, 'updated_at' => $at,
        ], range(1, 1000));
        $rows[] = ['group_id' => $group->getKey(), 'member_id' => $author->getKey(), 'in_reply_to_id' => null, 'body' => 'undated', 'created_at' => null, 'updated_at' => null];
        DB::table('group_messages')->insert($rows);
        $undated = DB::table('group_messages')->where('group_id', $group->getKey())->whereNull('created_at')->value('id');
        DB::table('reactions')->insert(['reactable_type' => 'groupMessage', 'reactable_id' => $undated, 'member_id' => $author->getKey(), 'emoji' => $this->emoji(0), 'created_at' => $at, 'updated_at' => $at]);

        app(DeleteGroup::class)->purge($group);

        $this->assertDatabaseCount('reactions', 0);
    }

    /** A page that ends on a null timestamp continues by id among the nulls and then on to the dated rows, since no timestamp compares past null. */
    public function test_the_talk_sweep_continues_past_a_page_of_undated_messages(): void
    {
        $group = $this->group();
        $author = $this->joined($group);
        $at = now();
        $rows = array_map(fn (int $i): array => [
            'group_id' => $group->getKey(), 'member_id' => $author->getKey(), 'in_reply_to_id' => null, 'body' => "m{$i}", 'created_at' => null, 'updated_at' => null,
        ], range(1, 1001));
        $rows[] = ['group_id' => $group->getKey(), 'member_id' => $author->getKey(), 'in_reply_to_id' => null, 'body' => 'dated', 'created_at' => $at, 'updated_at' => $at];
        DB::table('group_messages')->insert($rows);
        $lastUndated = DB::table('group_messages')->where('group_id', $group->getKey())->whereNull('created_at')->max('id');
        $dated = DB::table('group_messages')->where('group_id', $group->getKey())->whereNotNull('created_at')->value('id');
        DB::table('reactions')->insert(array_map(fn (int $id): array => [
            'reactable_type' => 'groupMessage', 'reactable_id' => $id, 'member_id' => $author->getKey(), 'emoji' => $this->emoji(0), 'created_at' => $at, 'updated_at' => $at,
        ], [$lastUndated, $dated]));
        $pages = 0;
        DB::listen(function ($query) use (&$pages): void {
            if (preg_match('/from [`"]group_messages[`"] where .*limit 1000$/', $query->sql)) {
                $pages++;
            }
        });

        app(DeleteGroup::class)->purge($group);

        $this->assertSame(2, $pages);
        $this->assertDatabaseCount('reactions', 0);
    }

    public function test_a_failed_teardown_leaves_the_boards_reactions_and_bytes(): void
    {
        $group = $this->group();
        $author = $this->joined($group);
        $topic = app(CreateTopic::class)($author, $group, new GroupTopicFormData('Topic', 'Body'), [UploadedFile::fake()->image('t.png', 20, 20)]);
        $comment = app(CreateTopicComment::class)($author, $topic, 'reply', []);
        $this->react($author, $comment)->assertOk();
        $file = $topic->images()->with('file')->firstOrFail()->file;

        Group::deleting(function (): void {
            throw new RuntimeException('the delete failed after the sweep');
        });

        try {
            app(DeleteGroup::class)->purge($group);
            $this->fail('the delete did not fail');
        } catch (RuntimeException) {
            // The rollback is what is under test.
        }

        $this->assertDatabaseHas('groups', ['id' => $group->getKey()]);
        $this->assertDatabaseCount('reactions', 1);
        $this->assertModelExists($file);
        $this->assertTrue(app(FileStorage::class)->exists($file));
    }

    public function test_reacting_to_a_comment_whose_topic_is_gone_writes_nothing(): void
    {
        $group = $this->group();
        $comment = $this->topicComment($group);
        DB::table('group_topic_comments')->where('id', $comment->getKey())->delete();

        try {
            app(AddReaction::class)($this->joined($group), $comment, $this->emoji(0), new BoardCommentReactionSurface);
            $this->fail('the write was not refused');
        } catch (ReactionRefused) {
            $this->addToAssertionCount(1);
        }

        $this->assertDatabaseCount('reactions', 0);
    }

    public function test_deleting_a_topic_that_is_already_gone_does_nothing(): void
    {
        $group = $this->group();
        $gone = GroupTopic::factory()->create(['group_id' => $group->getKey()]);
        $kept = $this->topicComment($group);
        $this->react($this->joined($group), $kept)->assertOk();
        DB::table('group_topics')->where('id', $gone->getKey())->delete();

        app(DeleteTopic::class)->purge($gone);

        $this->assertDatabaseCount('reactions', 1);
    }

    private function eventForm(): GroupEventFormData
    {
        return new GroupEventFormData(name: 'Event', body: 'Body', open_date: now()->addWeek()->toDateString(), open_date_comment: '', area: 'Here', application_deadline: null, capacity: null);
    }

    /** @return callable(): list<int> the transaction level each reaction sweep ran at, in order */
    private function sweepLevels(): callable
    {
        $levels = [];
        DB::listen(function ($query) use (&$levels): void {
            if (preg_match('/^delete from [`"]reactions[`"]/', $query->sql)) {
                $levels[] = $query->connection->transactionLevel();
            }
        });

        return function () use (&$levels): array {
            return $levels;
        };
    }

    /** @param  list<File>  $files */
    private function assertBytesGone(array $files): void
    {
        foreach ($files as $file) {
            $this->assertNull(File::find($file->getKey()));
            $this->assertFalse(app(FileStorage::class)->exists($file));
        }
    }
}
