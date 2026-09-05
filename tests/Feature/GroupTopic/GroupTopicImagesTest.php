<?php

namespace Tests\Feature\GroupTopic;

use App\Features\Group\GroupRole;
use App\Features\GroupTopic\Actions\CreateTopic;
use App\Features\GroupTopic\Actions\CreateTopicComment;
use App\Features\GroupTopic\Actions\DeleteTopic;
use App\Features\GroupTopic\Actions\DeleteTopicComment;
use App\Features\GroupTopic\Data\GroupTopicFormData;
use App\Features\GroupTopic\TopicReadAccess;
use App\Files\DiskFileStorage;
use App\Files\FileStorage;
use App\Models\File;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\GroupTopic;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class GroupTopicImagesTest extends TestCase
{
    use RefreshDatabase;

    private function joined(Group $group, GroupRole $role = GroupRole::Member): Member
    {
        $member = Member::factory()->create();
        GroupMember::factory()->create([
            'group_id' => $group->getKey(),
            'member_id' => $member->getKey(),
            'role' => $role,
        ]);

        return $member;
    }

    private function form(string $name = 'Topic', string $body = 'Body'): GroupTopicFormData
    {
        return new GroupTopicFormData(name: $name, body: $body);
    }

    public function test_a_topic_is_created_with_numbered_images_it_owns_and_shows_them(): void
    {
        $group = Group::factory()->create();
        $member = $this->joined($group);

        $this->actingAs($member)->post(route('group.topics.store', $group), [
            'name' => 'With pics',
            'body' => 'See attached.',
            'images' => [
                UploadedFile::fake()->image('a.png', 20, 20),
                UploadedFile::fake()->image('b.png', 20, 20),
            ],
        ])->assertRedirect();

        $topic = GroupTopic::where('name', 'With pics')->firstOrFail();
        $this->assertSame([1, 2], $topic->images()->pluck('number')->all());

        $file = $topic->images()->with('file')->first()->file;
        // The image File is owned by the topic, the source of its visibility.
        $this->assertSame('groupTopic', $file->related_entity_type);
        $this->assertSame($topic->getKey(), $file->related_entity_id);

        $this->actingAs($member)->get(route('group.topics.show', $topic))
            ->assertOk()
            ->assertSee($file->thumbnailUrl(120, 120, square: true), escape: false);
    }

    public function test_a_comment_is_posted_with_images_owned_by_the_comment(): void
    {
        $group = Group::factory()->create();
        $member = $this->joined($group);
        $topic = GroupTopic::factory()->create(['group_id' => $group->getKey()]);

        $this->actingAs($member)->post(route('group.topics.comment.store', $topic), [
            'body' => 'Reply with a pic',
            'images' => [UploadedFile::fake()->image('c.png', 20, 20)],
        ])->assertRedirect(route('group.topics.show', $topic));

        $comment = $topic->comments()->firstOrFail();
        $file = $comment->images()->with('file')->first()->file;
        $this->assertSame('groupTopicComment', $file->related_entity_type);
        $this->assertSame($comment->getKey(), $file->related_entity_id);
    }

    public function test_a_members_only_boards_topic_image_is_private_to_non_members(): void
    {
        $group = Group::factory()->create(['topic_read_access' => TopicReadAccess::MembersOnly]);
        $member = $this->joined($group);
        $topic = app(CreateTopic::class)($member, $group, $this->form(), [UploadedFile::fake()->image('x.png', 20, 20)]);
        $file = $topic->images()->with('file')->first()->file;

        // Inherits the board's read access: a stranger is denied (404, never 403), a member may fetch.
        $this->actingAs(Member::factory()->create())->get($file->url())->assertNotFound();
        $this->actingAs($member)->get($file->url())->assertOk();
    }

    public function test_deleting_a_topic_purges_its_and_its_comments_image_bytes(): void
    {
        $group = Group::factory()->create();
        $author = $this->joined($group);
        $topic = app(CreateTopic::class)($author, $group, $this->form(), [UploadedFile::fake()->image('t.png', 20, 20)]);
        app(CreateTopicComment::class)($author, $topic, 'reply', [UploadedFile::fake()->image('c.png', 20, 20)]);

        $topicFile = $topic->images()->with('file')->first()->file;
        $commentFile = $topic->comments()->firstOrFail()->images()->with('file')->first()->file;

        app(DeleteTopic::class)($author, $topic->fresh());

        // The link rows go with the cascade; the owned File bytes are purged explicitly.
        $this->assertNull(File::find($topicFile->getKey()));
        $this->assertNull(File::find($commentFile->getKey()));
        $this->assertSame(0, DB::table('file_bin')->whereIn('file_id', [$topicFile->getKey(), $commentFile->getKey()])->count());
        $this->assertDatabaseCount('group_topic_images', 0);
        $this->assertDatabaseCount('group_topic_comment_images', 0);
    }

    public function test_deleting_a_comment_purges_its_image_bytes(): void
    {
        $group = Group::factory()->create();
        $author = $this->joined($group);
        $topic = GroupTopic::factory()->create(['group_id' => $group->getKey(), 'member_id' => $author->getKey()]);
        $comment = app(CreateTopicComment::class)($author, $topic, 'reply', [UploadedFile::fake()->image('c.png', 20, 20)]);
        $file = $comment->images()->with('file')->first()->file;

        app(DeleteTopicComment::class)($author, $comment->fresh());

        $this->assertNull(File::find($file->getKey()));
        $this->assertSame(0, DB::table('file_bin')->where('file_id', $file->getKey())->count());
    }

    public function test_more_than_three_images_are_rejected(): void
    {
        $group = Group::factory()->create();
        $member = $this->joined($group);

        $this->actingAs($member)->post(route('group.topics.store', $group), [
            'name' => 'too many',
            'body' => 'b',
            'images' => [
                UploadedFile::fake()->image('1.png', 10, 10),
                UploadedFile::fake()->image('2.png', 10, 10),
                UploadedFile::fake()->image('3.png', 10, 10),
                UploadedFile::fake()->image('4.png', 10, 10),
            ],
        ])->assertSessionHasErrors('images');

        $this->assertDatabaseCount('group_topics', 0);
    }

    public function test_a_non_image_attachment_is_rejected(): void
    {
        $group = Group::factory()->create();
        $member = $this->joined($group);

        $this->actingAs($member)->post(route('group.topics.store', $group), [
            'name' => 'bad file',
            'body' => 'b',
            'images' => [UploadedFile::fake()->create('notes.txt', 10, 'text/plain')],
        ])->assertSessionHasErrors('images.0');

        $this->assertDatabaseCount('group_topics', 0);
    }

    public function test_a_failed_later_image_compensates_the_earlier_images_bytes(): void
    {
        // Drive a real disk backend so writeStream lands real bytes: a disk write is not
        // transactional, so nothing but compensation would take them back.
        config(['openpne.files.disk' => 'local']);
        Storage::fake('local');

        $real = new DiskFileStorage('local');
        $writes = 0;
        $this->instance(FileStorage::class, Mockery::mock(FileStorage::class, function ($mock) use ($real, &$writes) {
            $mock->shouldReceive('writeStream')->andReturnUsing(function ($file, $stream) use ($real, &$writes) {
                $writes++;
                if ($writes === 2) {
                    throw new RuntimeException('disk full');
                }
                $real->writeStream($file, $stream);
            });
            $mock->shouldReceive('delete')->andReturnUsing(fn ($file) => $real->delete($file));
            $mock->shouldReceive('readStream')->andReturnUsing(fn ($file) => $real->readStream($file));
            $mock->shouldReceive('exists')->andReturnUsing(fn ($file) => $real->exists($file));
        }));

        $group = Group::factory()->create();
        $member = $this->joined($group);

        try {
            app(CreateTopic::class)($member, $group, $this->form(), [
                UploadedFile::fake()->image('1.png', 20, 20),
                UploadedFile::fake()->image('2.png', 20, 20),
            ]);
            $this->fail('expected the failed image store to throw');
        } catch (RuntimeException) {
            // expected
        }

        // The transaction rolled back wholesale: no topic, no File rows, no link rows.
        $this->assertDatabaseCount('group_topics', 0);
        $this->assertDatabaseCount('files', 0);
        $this->assertDatabaseCount('group_topic_images', 0);
        // And the first image's bytes were compensated — no orphan left on disk.
        $this->assertEmpty(Storage::disk('local')->allFiles());
    }
}
