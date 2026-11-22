<?php

namespace Tests\Feature\Group\Reactions;

use App\Features\Group\Actions\DeleteGroup;
use App\Features\Group\BoardReactionSurface;
use App\Features\Group\BoardSweep;
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
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\SqliteBindLimit;

/**
 * Single-connection: the rows and bytes each delete reclaims, and the transaction they are reclaimed
 * in. What the locks add is BoardReactionLockOrderTest's, on MySQL.
 */
class BoardTeardownTest extends BoardReactionTestCase
{
    use SqliteBindLimit;

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

    public function test_deleting_a_topic_sweeps_its_own_and_its_comments_reactions_and_purges_the_bytes(): void
    {
        $group = $this->group();
        $author = $this->joined($group);
        $topic = app(CreateTopic::class)($author, $group, new GroupTopicFormData('Topic', 'Body'), [UploadedFile::fake()->image('t.png', 20, 20)]);
        $comment = app(CreateTopicComment::class)($author, $topic, 'reply', [UploadedFile::fake()->image('c.png', 20, 20)]);
        $this->react($author, $comment)->assertOk();
        $this->react($author, $topic)->assertOk();
        $files = [$topic->images()->with('file')->firstOrFail()->file, $comment->images()->with('file')->firstOrFail()->file];
        $levels = $this->sweepLevels();

        app(DeleteTopic::class)->purge($topic->fresh());

        // The topic's own reactions and its comments' go in two deletes, both inside the one transaction.
        $this->assertSame([DB::transactionLevel() + 1, DB::transactionLevel() + 1], $levels());
        $this->assertDatabaseCount('reactions', 0);
        $this->assertBytesGone($files);
    }

    public function test_deleting_an_event_sweeps_its_own_and_its_comments_reactions_and_purges_the_bytes(): void
    {
        $group = $this->group();
        $author = $this->joined($group);
        $event = app(CreateEvent::class)($author, $group, $this->eventForm(), [UploadedFile::fake()->image('e.png', 20, 20)]);
        $comment = app(CreateEventComment::class)($author, $event, 'reply', [UploadedFile::fake()->image('c.png', 20, 20)]);
        $this->react($author, $comment)->assertOk();
        $this->react($author, $event)->assertOk();
        $files = [$event->images()->with('file')->firstOrFail()->file, $comment->images()->with('file')->firstOrFail()->file];
        $levels = $this->sweepLevels();

        app(DeleteEvent::class)->purge($event->fresh());

        $this->assertSame([DB::transactionLevel() + 1, DB::transactionLevel() + 1], $levels());

        $this->assertDatabaseCount('reactions', 0);
        $this->assertBytesGone($files);
    }

    /** Every board row's reactions and bytes go, in one transaction under the group row. */
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
        $this->react($author, $topic)->assertOk();
        $this->react($author, $event)->assertOk();
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
        $this->assertSame(array_fill(0, 4, DB::transactionLevel() + 1), $levels());
        $this->assertDatabaseMissing('groups', ['id' => $group->getKey()]);
        $this->assertDatabaseCount('group_topics', 1);
        $this->assertDatabaseCount('reactions', 1);
        $this->assertDatabaseHas('reactions', ['reactable_id' => $otherGroupsComment->getKey()]);
        $this->assertBytesGone($files);
    }

    /** The placeholders do not grow with the group: MySQL caps a prepared statement at 65,535, SQLite before 3.32 at 999, and a decade-old board's comments pass both. */
    public function test_the_teardown_binds_nothing_but_the_group_id_for_the_files_and_deletes_by_the_reactions_own_ids(): void
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
        $this->react($author, $topic)->assertOk();
        $this->react($author, $event)->assertOk();
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            if (preg_match('/^select .* from [`"]files[`"] where [`"]id[`"] in \(select|^delete from [`"]reactions[`"]/', $query->sql)) {
                $queries[] = $query;
            }
        });

        app(DeleteGroup::class)->purge($group);

        $this->assertCount(5, $queries, 'one File collection and one chunked reaction delete per board arm: the two bodies, then the two comment sets');
        $this->assertSame([$group->getKey()], array_values(array_unique($queries[0]->bindings)), 'the File collection binds the group id, however many times, and nothing else');
        foreach ([1 => 1, 2 => 1, 3 => 3, 4 => 1] as $at => $reactions) {
            $this->assertSame([], $queries[$at]->bindings, 'a reaction delete binds nothing');
            $this->assertCount($reactions, self::deletedIds($queries[$at]->sql), 'a reaction delete names the reactions\' own ids');
        }
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
        foreach (array_chunk($rows, 100) as $part) {
            DB::table('reactions')->insert($part);
        }
        $this->assertGreaterThan(1000, count($rows));
        $deletes = [];
        DB::listen(function ($query) use (&$deletes): void {
            if (preg_match('/^delete from [`"]reactions[`"]/', $query->sql)) {
                $deletes[] = count(self::deletedIds($query->sql));
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
        foreach (array_chunk(array_map(fn (int $i): array => [
            'group_topic_id' => $topic->getKey(), 'member_id' => $author->getKey(), 'number' => $i, 'body' => 'B', 'created_at' => $at, 'updated_at' => $at,
        ], range(1, 1001)), 100) as $part) {
            DB::table('group_topic_comments')->insert($part);
        }
        $commentIds = DB::table('group_topic_comments')->where('group_topic_id', $topic->getKey())->orderBy('id')->pluck('id')->all();
        foreach (array_chunk(array_map(fn (int $id): array => [
            'reactable_type' => 'groupTopicComment', 'reactable_id' => $id, 'member_id' => $author->getKey(), 'emoji' => $this->emoji(0), 'created_at' => $at, 'updated_at' => $at,
        ], $commentIds), 100) as $part) {
            DB::table('reactions')->insert($part);
        }
        $pages = [];
        $deletes = [];
        DB::listen(function ($query) use (&$pages, &$deletes): void {
            if (preg_match('/from [`"]group_topic_comments[`"] where .*order by [`"]number[`"] asc, [`"]id[`"] asc limit 1000$/', $query->sql)) {
                $pages[] = $query->bindings;
            }
            if (preg_match('/^delete from [`"]reactions[`"]/', $query->sql)) {
                $deletes[] = count(self::deletedIds($query->sql));
            }
        });

        app(DeleteGroup::class)->purge($group);

        $this->assertCount(2, $pages, 'a thousand and one comments are two pages');
        // The second page's cursor is the OR of two arms, number above or number equal with id above, so four bindings with the parent.
        $this->assertCount(4, $pages[1]);
        $this->assertSame($commentIds[999], $pages[1][3], 'the cursor is the last row of the page');
        $this->assertSame([1000, 1], $deletes);
        $this->assertDatabaseCount('reactions', 0);
    }

    /** The room is paged in its own index's order, (created_at, id) under the group, so no page sorts or scans past the room. */
    public function test_the_talk_sweep_pages_the_messages_in_the_room_index_order(): void
    {
        $group = $this->group();
        $author = $this->joined($group);
        $at = now();
        foreach (array_chunk(array_map(fn (int $i): array => [
            'group_id' => $group->getKey(), 'member_id' => $author->getKey(), 'in_reply_to_id' => null, 'body' => "m{$i}", 'created_at' => $at, 'updated_at' => $at,
        ], range(1, 1001)), 100) as $part) {
            DB::table('group_messages')->insert($part);
        }
        $messageIds = DB::table('group_messages')->where('group_id', $group->getKey())->orderBy('id')->pluck('id')->all();
        foreach (array_chunk(array_map(fn (int $id): array => [
            'reactable_type' => 'groupMessage', 'reactable_id' => $id, 'member_id' => $author->getKey(), 'emoji' => $this->emoji(0), 'created_at' => $at, 'updated_at' => $at,
        ], $messageIds), 100) as $part) {
            DB::table('reactions')->insert($part);
        }
        $pages = [];
        DB::listen(function ($query) use (&$pages): void {
            if (preg_match('/from [`"]group_messages[`"] where .*order by [`"]created_at[`"] asc, [`"]id[`"] asc limit 1000$/', $query->sql)) {
                $pages[] = ['sql' => $query->sql, 'bindings' => $query->bindings];
            }
        });

        app(DeleteGroup::class)->purge($group);

        // Two pages: the first carries no cursor, the second starts after its last (created_at, id), all one timestamp here.
        $this->assertCount(2, $pages);
        $this->assertSame([], $pages[0]['bindings'], 'the group id is inlined, not bound');
        $this->assertStringContainsString("`group_id` = {$group->getKey()}", $pages[1]['sql']);
        $this->assertCount(3, $pages[1]['bindings'], 'the cursor is bound as the two arms');
        $this->assertSame($messageIds[999], $pages[1]['bindings'][2], 'the cursor is the last row of the page');
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
        foreach (array_chunk($rows, 100) as $part) {
            DB::table('group_messages')->insert($part);
        }
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
        foreach (array_chunk($rows, 100) as $part) {
            DB::table('group_messages')->insert($part);
        }
        $lastUndated = DB::table('group_messages')->where('group_id', $group->getKey())->whereNull('created_at')->max('id');
        $dated = DB::table('group_messages')->where('group_id', $group->getKey())->whereNotNull('created_at')->value('id');
        foreach (array_chunk(array_map(fn (int $id): array => [
            'reactable_type' => 'groupMessage', 'reactable_id' => $id, 'member_id' => $author->getKey(), 'emoji' => $this->emoji(0), 'created_at' => $at, 'updated_at' => $at,
        ], [$lastUndated, $dated]), 100) as $part) {
            DB::table('reactions')->insert($part);
        }
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
        $this->react($author, $topic)->assertOk();
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
        $this->assertDatabaseCount('reactions', 2);
        $this->assertModelExists($file);
        $this->assertTrue(app(FileStorage::class)->exists($file));
    }

    /** The parent goes between the route binding and the gate's own read of it, where a typed relation would answer 500. */
    #[DataProvider('comments')]
    public function test_a_comment_whose_parent_went_after_the_binding_is_not_found(string $make): void
    {
        $group = $this->group();
        $member = $this->joined($group);
        $comment = $this->{$make}($group);
        [$table, $column] = $comment instanceof GroupTopicComment ? ['group_topics', 'group_topic_id'] : ['group_events', 'group_event_id'];
        $comment::retrieved(function ($retrieved) use ($comment, $table, $column): void {
            if ($retrieved->is($comment)) {
                DB::table($table)->where('id', $comment->{$column})->delete();
            }
        });

        $this->react($member, $comment)->assertNotFound();
        $this->unreact($member, $comment)->assertNotFound();
        $this->actingAs($member)->getJson($this->path($comment))->assertNotFound();
        $this->assertDatabaseCount('reactions', 0);
    }

    /** The group goes between the binding of a body and the gate, which reads the group off it. */
    #[DataProvider('bodies')]
    public function test_a_body_whose_group_went_after_the_binding_is_not_found(string $make): void
    {
        $group = $this->group();
        $member = $this->joined($group);
        $body = $this->{$make}($group);
        $body::retrieved(function ($retrieved) use ($body): void {
            if ($retrieved->is($body)) {
                DB::table('groups')->where('id', $body->group_id)->delete();
            }
        });

        $this->react($member, $body)->assertNotFound();
        $this->unreact($member, $body)->assertNotFound();
        $this->actingAs($member)->getJson($this->path($body))->assertNotFound();
        $this->assertDatabaseCount('reactions', 0);
    }

    /** @return array<string, list<string>> */
    public static function comments(): array
    {
        return ['topic' => ['topicComment'], 'event' => ['eventComment']];
    }

    /** @return array<string, list<string>> */
    public static function bodies(): array
    {
        return ['topic' => ['topicBody'], 'event' => ['eventBody']];
    }

    /** The group's rows are paged in the (group_id, bumped_at) index's order with the id breaking ties, so no page sorts the group. */
    public function test_the_body_sweep_pages_the_rows_in_the_index_order(): void
    {
        $group = $this->group();
        $author = $this->joined($group);
        // Rows 999 and 1000 share the instant on the page boundary, so the cursor has to carry the id; row 1002 is early with the highest id, so the cursor must be the page's last row, not its highest id.
        $earlier = now()->subDay();
        $seconds = fn (int $i): int => match (true) {
            $i === 1000 => 999,
            $i === 1001 => 1000,
            $i === 1002 => 1,
            default => $i,
        };
        foreach (array_chunk(array_map(fn (int $i): array => [
            'group_id' => $group->getKey(),
            'member_id' => $author->getKey(),
            'name' => "Topic {$i}",
            'body' => 'Body',
            'bumped_at' => $earlier->copy()->addSeconds($seconds($i)),
            'created_at' => $earlier,
            'updated_at' => $earlier,
        ], range(1, 1002)), 100) as $part) {
            DB::table('group_topics')->insert($part);
        }
        $bumpedAt = DB::table('group_topics')->where('group_id', $group->getKey())->orderBy('bumped_at')->orderBy('id')->pluck('bumped_at', 'id')->all();
        $ordered = array_keys($bumpedAt);
        $this->assertSame($bumpedAt[$ordered[999]], $bumpedAt[$ordered[1000]], 'the boundary rows share an instant');
        $this->assertGreaterThan($ordered[999], max(array_slice($ordered, 0, 1000)), 'the page holds an id above its last row');
        foreach (array_chunk(array_map(fn (int $id): array => [
            'reactable_type' => 'groupTopic', 'reactable_id' => $id, 'member_id' => $author->getKey(), 'emoji' => $this->emoji(0), 'created_at' => $earlier, 'updated_at' => $earlier,
        ], $ordered), 100) as $part) {
            DB::table('reactions')->insert($part);
        }
        $pages = [];
        $deletes = [];
        DB::listen(function ($query) use (&$pages, &$deletes): void {
            if (preg_match('/from [`"]group_topics[`"] where .*order by [`"]bumped_at[`"] asc, [`"]id[`"] asc limit 1000$/', $query->sql)) {
                $pages[] = $query->bindings;
            }
            if (preg_match('/^delete from [`"]reactions[`"]/', $query->sql)) {
                $deletes[] = self::deletedIds($query->sql);
            }
        });
        $secondPage = DB::table('reactions')->whereIn('reactable_id', array_slice($ordered, 1000))->orderBy('id')->pluck('id')->all();

        app(DeleteGroup::class)->purge($group);

        $this->assertCount(2, $pages, 'a thousand and two topics are two pages');
        // The second page's cursor is the OR of two arms, time above or time equal with id above, so four bindings with the group.
        $this->assertCount(4, $pages[1]);
        $this->assertSame($ordered[999], $pages[1][3], 'the cursor is the last row of the page in the index order, not the highest id');
        $this->assertSame([1000, 2], array_map('count', $deletes));
        $this->assertSame($secondPage, $deletes[1], 'the row sharing the boundary instant and the one after it are reached by the second page');
        $this->assertDatabaseCount('reactions', 0);
    }

    /** Past a page of the group's rows the body sweep goes on to the next and still reaches every row: the delete statements read [1000, remainder]. */
    public function test_the_body_sweep_pages_the_reactions_past_the_chunk_size(): void
    {
        $group = $this->group();
        $author = $this->joined($group);
        $first = GroupTopic::factory()->create(['group_id' => $group->getKey(), 'member_id' => $author->getKey()]);
        foreach (array_chunk(array_map(fn (int $i): array => [
            'group_id' => $group->getKey(),
            'member_id' => $author->getKey(),
            'name' => "Topic {$i}",
            'body' => 'Body',
            'bumped_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ], range(2, 1001)), 100) as $part) {
            DB::table('group_topics')->insert($part);
        }
        foreach (array_chunk(DB::table('group_topics')->where('group_id', $group->getKey())->pluck('id')->map(fn (int $id): array => [
            'reactable_type' => $first->getMorphClass(),
            'reactable_id' => $id,
            'member_id' => $author->getKey(),
            'emoji' => $this->emoji(0),
            'created_at' => now(),
            'updated_at' => now(),
        ])->all(), 100) as $part) {
            DB::table('reactions')->insert($part);
        }
        $sizes = [];
        DB::listen(function ($query) use (&$sizes): void {
            if (preg_match('/^delete from [`"]reactions[`"]/', $query->sql)) {
                $sizes[] = count(self::deletedIds($query->sql));
            }
        });

        app(DeleteGroup::class)->purge($group);

        $this->assertSame([1000, 1], $sizes);
        $this->assertDatabaseCount('reactions', 0);
    }

    public function test_reacting_to_a_comment_whose_topic_is_gone_writes_nothing(): void
    {
        $group = $this->group();
        $comment = $this->topicComment($group);
        DB::table('group_topic_comments')->where('id', $comment->getKey())->delete();

        try {
            app(AddReaction::class)($this->joined($group), $comment, $this->emoji(0), new BoardReactionSurface);
            $this->fail('the write was not refused');
        } catch (ReactionRefused) {
            $this->addToAssertionCount(1);
        }

        $this->assertDatabaseCount('reactions', 0);
    }

    public function test_the_file_purge_reaches_past_one_chunk_of_files(): void
    {
        $now = now();
        foreach (array_chunk(range(1, 1001), 100) as $part) {
            DB::table('files')->insert(array_map(fn (int $i): array => [
                'name' => "bulk-{$i}", 'type' => 'image/png', 'byte_size' => 0, 'created_at' => $now, 'updated_at' => $now,
            ], $part));
        }
        $ids = DB::table('files')->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();

        $this->withinSqliteBindLimit(fn () => BoardSweep::files($ids));

        $this->assertDatabaseCount('files', 0);
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

    /** @return list<int> */
    private static function deletedIds(string $sql): array
    {
        self::assertSame(1, preg_match('/^delete from [`"]reactions[`"] where [`"]id[`"] in \(([\d, ]+)\)$/', $sql, $match), $sql);

        return array_map('intval', explode(', ', $match[1]));
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
