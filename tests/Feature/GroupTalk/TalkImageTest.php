<?php

namespace Tests\Feature\GroupTalk;

use App\Features\Group\Actions\DeleteGroup;
use App\Features\GroupTalk\Actions\CreateGroupMessage;
use App\Features\GroupTopic\TopicReadAccess;
use App\Files\DiskFileStorage;
use App\Files\FileStorage;
use App\Models\File;
use App\Models\Group;
use App\Models\GroupMessage;
use App\Models\Member;
use App\Support\SnsSettingKey;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;

/**
 * Up to PostImages::MAX_IMAGES per message, numbered in pick order. The schema numbers slots past
 * that so migrated content can carry more.
 */
class TalkImageTest extends TalkTestCase
{
    private function upload(string $name = 'shot.png'): UploadedFile
    {
        return UploadedFile::fake()->image($name, 40, 40);
    }

    private function attachedFile(GroupMessage $message): ?File
    {
        return $message->images()->with('file')->first()?->file;
    }

    /** @return list<File> */
    private function attachedFiles(GroupMessage $message): array
    {
        return $message->images()->with('file')->orderBy('number')->get()->pluck('file')->filter()->values()->all();
    }

    public function test_a_message_can_carry_an_image(): void
    {
        $group = $this->group();
        $author = $this->memberOf($group);

        $id = $this->actingAs($author)
            ->post("/groups/{$group->getKey()}/talk", ['body' => 'look at this', 'images' => [$this->upload()]])
            ->assertCreated()
            ->json('id');

        $message = GroupMessage::findOrFail($id);
        $this->assertDatabaseHas('group_message_images', ['group_message_id' => $id, 'number' => 1]);
        $this->assertNotNull($this->attachedFile($message));
        $this->assertSame('groupMessage', $this->attachedFile($message)->related_entity_type);
    }

    /**
     * The single-image wire this endpoint spoke before images[]: a talk tab open across the deploy
     * keeps sending it, and its attachment must land rather than be silently dropped with a 201.
     */
    public function test_the_legacy_single_image_wire_still_attaches(): void
    {
        $group = $this->group();
        $author = $this->memberOf($group);

        $id = $this->actingAs($author)
            ->post("/groups/{$group->getKey()}/talk", ['body' => 'from the old tab', 'image' => $this->upload()])
            ->assertCreated()
            ->json('id');

        $message = GroupMessage::findOrFail($id);
        $this->assertDatabaseHas('group_message_images', ['group_message_id' => $id, 'number' => 1]);
        $this->assertNotNull($this->attachedFile($message));
    }

    /** No client speaks both wires at once; a request that does is malformed, not a bigger cap. */
    public function test_the_two_wires_refuse_to_combine(): void
    {
        $group = $this->group();
        $author = $this->memberOf($group);

        $this->actingAs($author)
            ->post("/groups/{$group->getKey()}/talk", [
                'body' => 'both at once',
                'image' => $this->upload(),
                'images' => [$this->upload('other.png')],
            ])
            ->assertSessionHasErrors('image');

        $this->assertDatabaseCount('group_messages', 0);
        $this->assertDatabaseCount('files', 0);
    }

    /** Slots are the pick order, not the order the storage backend happened to answer in. */
    public function test_three_images_land_in_slots_one_to_three_in_pick_order(): void
    {
        $group = $this->group();
        $author = $this->memberOf($group);

        $id = $this->actingAs($author)
            ->post("/groups/{$group->getKey()}/talk", [
                'body' => 'look at these',
                'images' => [$this->upload('first.png'), $this->upload('second.png'), $this->upload('third.png')],
            ])
            ->assertCreated()
            ->json('id');

        $names = GroupMessage::findOrFail($id)->images()->with('file')->orderBy('number')->get()
            ->map(fn ($image) => $image->file?->original_filename)
            ->all();

        $this->assertSame(['first.png', 'second.png', 'third.png'], $names);
        $this->assertSame([1, 2, 3], GroupMessage::findOrFail($id)->images()->orderBy('number')->pluck('number')->all());
    }

    /** Over the cap the whole message is refused — nothing half-posts and the composer keeps its draft. */
    public function test_a_fourth_image_takes_the_whole_message_down(): void
    {
        $group = $this->group();
        $author = $this->memberOf($group);

        $this->actingAs($author)
            ->postJson("/groups/{$group->getKey()}/talk", [
                'body' => 'look',
                'images' => [$this->upload(), $this->upload(), $this->upload(), $this->upload()],
            ])
            ->assertJsonValidationErrorFor('images');

        $this->assertDatabaseCount('group_messages', 0);
        $this->assertDatabaseCount('group_message_images', 0);
        $this->assertDatabaseCount('files', 0);
    }

    /** The attach shares the write, so the sender's cursor still passes their own message. */
    public function test_the_cursor_still_advances_when_an_image_rides_along(): void
    {
        $group = $this->group();
        $author = $this->memberOf($group);

        $id = $this->actingAs($author)
            ->post("/groups/{$group->getKey()}/talk", ['body' => 'look', 'images' => [$this->upload()]])
            ->assertCreated()
            ->json('id');

        $cursor = DB::table('group_members')
            ->where('group_id', $group->getKey())->where('member_id', $author->getKey())
            ->value('talk_read_message_id');

        $this->assertSame((int) $id, (int) $cursor);
    }

    public function test_the_serializer_ships_the_image(): void
    {
        $group = $this->group();
        $author = $this->memberOf($group);
        $this->actingAs($author)->post("/groups/{$group->getKey()}/talk", ['body' => 'look', 'images' => [$this->upload()]]);

        $this->actingAs($author)->get("/groups/{$group->getKey()}/talk")
            ->assertInertia(fn ($page) => $page
                ->has('page.messages.0.images', 1)
                ->has('page.messages.0.images.0.url')
                ->has('page.messages.0.images.0.thumbnailUrl'));
    }

    /** The write's own response is what the composer appends, so it has to carry the same list. */
    public function test_the_write_response_ships_all_three_in_slot_order(): void
    {
        $group = $this->group();
        $author = $this->memberOf($group);

        $response = $this->actingAs($author)
            ->postJson("/groups/{$group->getKey()}/talk", [
                'body' => 'look',
                'images' => [$this->upload('a.png'), $this->upload('b.png'), $this->upload('c.png')],
            ])
            ->assertCreated();

        $response->assertJsonCount(3, 'images');
        $ids = $response->json('images.*.id');
        $this->assertSame($ids, array_values(array_unique($ids)));
        $this->assertSame(
            $this->attachedFiles(GroupMessage::findOrFail($response->json('id')))[0]->url(),
            $response->json('images.0.url'),
        );
    }

    public function test_a_message_without_an_image_ships_an_empty_list(): void
    {
        $group = $this->group();
        $author = $this->memberOf($group);
        GroupMessage::factory()->create(['group_id' => $group->getKey(), 'member_id' => $author->getKey()]);

        $this->actingAs($author)->get("/groups/{$group->getKey()}/talk")
            ->assertInertia(fn ($page) => $page->where('page.messages.0.images', []));
    }

    /**
     * The compensating flow owns the outermost transaction, so a failure after the bytes are stored
     * must take both the row and the bytes with it. A byte write is not transactional: without
     * compensation the rollback would drop the `files` row and leave the bytes orphaned on disk.
     *
     * Driven against a real disk backend, because that is the only backend where the two can come
     * apart — asserting over `files` rows alone would pass even with no compensation at all.
     */
    public function test_a_failure_after_the_bytes_are_stored_leaves_no_message_and_no_bytes(): void
    {
        config(['openpne.files.disk' => 'local']);
        Storage::fake('local');
        $real = new DiskFileStorage('local');
        $this->instance(FileStorage::class, Mockery::mock(FileStorage::class, function ($mock) use ($real) {
            $mock->shouldReceive('writeStream')->andReturnUsing(fn ($file, $stream) => $real->writeStream($file, $stream));
            $mock->shouldReceive('delete')->andReturnUsing(fn ($file) => $real->delete($file));
            $mock->shouldReceive('readStream')->andReturnUsing(fn ($file) => $real->readStream($file));
            $mock->shouldReceive('exists')->andReturnUsing(fn ($file) => $real->exists($file));
        }));

        $group = $this->group();
        $author = $this->memberOf($group);

        // Fail the join-row insert: the one step that runs after the bytes have already landed.
        // Matched without identifier quotes — sqlite emits `"` and MySQL emits a backtick, and a
        // grammar-bound match silently never fires on the other engine (this test then swallowed
        // its own fail(): AssertionFailedError IS a RuntimeException, hence the exact-message check).
        DB::listen(function ($query) {
            if (str_starts_with($query->sql, 'insert') && str_contains($query->sql, 'group_message_images')) {
                throw new RuntimeException('boom');
            }
        });

        try {
            app(CreateGroupMessage::class)($author, $group, 'look', [], [$this->upload()]);
            $this->fail('the write should have thrown');
        } catch (RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage(), 'the write failed for the wrong reason');
        }

        $this->assertDatabaseCount('group_messages', 0);
        $this->assertDatabaseCount('group_message_images', 0);
        $this->assertDatabaseCount('files', 0);
        $this->assertEmpty(Storage::disk('local')->allFiles(), 'the stored bytes must be compensated off the disk');
    }

    /** Every slot, not just the first: a purge that stopped at one would strand two files' bytes. */
    public function test_deleting_the_message_purges_every_slots_bytes(): void
    {
        $group = $this->group();
        $author = $this->memberOf($group);
        $id = $this->actingAs($author)
            ->post("/groups/{$group->getKey()}/talk", [
                'body' => 'look',
                'images' => [$this->upload('a.png'), $this->upload('b.png'), $this->upload('c.png')],
            ])
            ->json('id');
        $files = $this->attachedFiles(GroupMessage::findOrFail($id));
        $this->assertCount(3, $files);

        $this->actingAs($author)
            ->post("/groups/{$group->getKey()}/talk/messages/{$id}/delete")
            ->assertNoContent();

        foreach ($files as $file) {
            $this->assertDatabaseMissing('files', ['id' => $file->getKey()]);
            $this->assertFalse(app(FileStorage::class)->exists($file));
        }
    }

    /** The group cascade drops the join rows but never the bytes — DeleteGroup has to reclaim them all. */
    public function test_deleting_the_group_purges_every_slots_talk_bytes(): void
    {
        $group = $this->group();
        $author = $this->adminOf($group);
        $id = $this->actingAs($author)
            ->post("/groups/{$group->getKey()}/talk", [
                'body' => 'look',
                'images' => [$this->upload('a.png'), $this->upload('b.png'), $this->upload('c.png')],
            ])
            ->json('id');
        $files = $this->attachedFiles(GroupMessage::findOrFail($id));
        $this->assertCount(3, $files);

        app(DeleteGroup::class)->purge($group);

        $this->assertDatabaseMissing('groups', ['id' => $group->getKey()]);
        foreach ($files as $file) {
            $this->assertDatabaseMissing('files', ['id' => $file->getKey()]);
            $this->assertFalse(app(FileStorage::class)->exists($file));
        }
    }

    // --- FilePolicy: a talk image inherits the conversation's read gate ---

    private function talkImage(GroupMessage $message): File
    {
        return File::factory()->create([
            'type' => 'image/png',
            'related_entity_type' => 'groupMessage',
            'related_entity_id' => $message->getKey(),
        ]);
    }

    private function messageIn(Group $group): GroupMessage
    {
        return GroupMessage::factory()->create([
            'group_id' => $group->getKey(),
            'member_id' => $this->memberOf($group)->getKey(),
        ]);
    }

    public function test_an_everyone_groups_image_is_visible_to_any_member(): void
    {
        $image = $this->talkImage($this->messageIn($this->group(TopicReadAccess::Everyone)));

        $this->assertTrue(Gate::forUser(Member::factory()->create())->allows('view', $image));
    }

    public function test_a_members_only_groups_image_is_hidden_from_a_non_member(): void
    {
        $group = $this->group(TopicReadAccess::MembersOnly);
        $image = $this->talkImage($this->messageIn($group));

        $this->assertFalse(Gate::forUser(Member::factory()->create())->allows('view', $image));
        $this->assertTrue(Gate::forUser($this->memberOf($group))->allows('view', $image));
    }

    public function test_a_guest_never_sees_a_talk_image(): void
    {
        $image = $this->talkImage($this->messageIn($this->group(TopicReadAccess::Everyone)));

        $this->assertFalse(Gate::forUser(null)->allows('view', $image));
    }

    public function test_switching_the_unit_off_takes_the_image_with_it(): void
    {
        $group = $this->group(TopicReadAccess::Everyone);
        $image = $this->talkImage($this->messageIn($group));
        $viewer = $this->memberOf($group);
        $this->assertTrue(Gate::forUser($viewer)->allows('view', $image));

        $this->setSnsSetting(SnsSettingKey::FeatureGroupTalkEnabled, false);
        $this->freshRequestState();

        $this->assertFalse(Gate::forUser($viewer)->allows('view', $image));
    }

    public function test_the_bytes_are_served_to_a_reader_and_refused_to_an_outsider(): void
    {
        $group = $this->group(TopicReadAccess::MembersOnly);
        $author = $this->memberOf($group);
        $id = $this->actingAs($author)
            ->post("/groups/{$group->getKey()}/talk", ['body' => 'look', 'images' => [$this->upload()]])
            ->json('id');
        $url = $this->attachedFile(GroupMessage::findOrFail($id))->url();

        $this->actingAs($author)->get($url)->assertOk();
        $this->actingAs(Member::factory()->create())->get($url)->assertNotFound();
    }

    // --- validation ---

    /** Keyed by slot, so the composer can put the message under the picture it belongs to. */
    public function test_a_refused_file_is_a_422_naming_its_slot(): void
    {
        $group = $this->group();
        $author = $this->memberOf($group);

        $this->actingAs($author)
            ->postJson("/groups/{$group->getKey()}/talk", [
                'body' => 'look',
                'images' => [$this->upload(), UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')],
            ])
            ->assertJsonValidationErrorFor('images.1');

        $this->assertDatabaseCount('group_messages', 0);
        $this->assertDatabaseCount('files', 0);
    }

    /**
     * The multipart trap from the timeline campaign: FormData encodes the textarea's LF newlines as
     * CRLF in transit, so a mention offset computed over the LF value would be one position short
     * per preceding line break. The form request re-normalizes before it measures anything.
     */
    public function test_a_multipart_send_with_newlines_keeps_its_mention_offsets(): void
    {
        $group = $this->group();
        $author = $this->memberOf($group);
        $target = Member::factory()->create(['name' => 'Bob']);
        DB::table('group_members')->insert([
            'group_id' => $group->getKey(), 'member_id' => $target->getKey(), 'role' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Offsets are the ones a browser computes over the DOM value, whose newlines are LF.
        $lfBody = "one\ntwo\n@Bob";
        $offset = mb_strpos($lfBody, '@Bob');

        $id = $this->actingAs($author)
            ->post("/groups/{$group->getKey()}/talk", [
                // What the wire actually carries.
                'body' => str_replace("\n", "\r\n", $lfBody),
                'mentions' => [['member_id' => $target->getKey(), 'offset' => $offset, 'length' => 4]],
                'images' => [$this->upload()],
            ])
            ->assertCreated()
            ->json('id');

        $this->assertSame($lfBody, GroupMessage::findOrFail($id)->body, 'the body is stored with LF');
        $this->assertDatabaseHas('group_message_mentions', [
            'group_message_id' => $id,
            'member_id' => $target->getKey(),
            'offset' => $offset,
        ]);
        $this->assertDatabaseCount('group_message_images', 1);
    }
}
