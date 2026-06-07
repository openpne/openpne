<?php

namespace Tests\Feature\CommunityTopic;

use App\Features\Community\CommunityRole;
use App\Features\CommunityTopic\Actions\CreateTopic;
use App\Features\CommunityTopic\Actions\CreateTopicComment;
use App\Features\CommunityTopic\Actions\DeleteTopic;
use App\Features\CommunityTopic\Actions\DeleteTopicComment;
use App\Features\CommunityTopic\Data\CommunityTopicFormData;
use App\Features\CommunityTopic\TopicReadAccess;
use App\Files\DiskFileStorage;
use App\Files\FileStorage;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\CommunityTopic;
use App\Models\File;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class CommunityTopicImagesTest extends TestCase
{
    use RefreshDatabase;

    private function joined(Community $community, CommunityRole $role = CommunityRole::Member): Member
    {
        $member = Member::factory()->create();
        CommunityMember::factory()->create([
            'community_id' => $community->getKey(),
            'member_id' => $member->getKey(),
            'role' => $role,
        ]);

        return $member;
    }

    private function form(string $name = 'Topic', string $body = 'Body'): CommunityTopicFormData
    {
        return new CommunityTopicFormData(name: $name, body: $body);
    }

    public function test_a_topic_is_created_with_numbered_images_it_owns_and_shows_them(): void
    {
        $community = Community::factory()->create();
        $member = $this->joined($community);

        $this->actingAs($member)->post(route('communityTopic.store', $community), [
            'name' => 'With pics',
            'body' => 'See attached.',
            'images' => [
                UploadedFile::fake()->image('a.png', 20, 20),
                UploadedFile::fake()->image('b.png', 20, 20),
            ],
        ])->assertRedirect();

        $topic = CommunityTopic::where('name', 'With pics')->firstOrFail();
        $this->assertSame([1, 2], $topic->images()->pluck('number')->all());

        $file = $topic->images()->with('file')->first()->file;
        // The image File is owned by the topic, the source of its visibility.
        $this->assertSame('communityTopic', $file->related_entity_type);
        $this->assertSame($topic->getKey(), $file->related_entity_id);

        $this->actingAs($member)->get(route('communityTopic.show', $topic))
            ->assertOk()
            ->assertSee($file->thumbnailUrl(120, 120, square: true), escape: false);
    }

    public function test_a_comment_is_posted_with_images_owned_by_the_comment(): void
    {
        $community = Community::factory()->create();
        $member = $this->joined($community);
        $topic = CommunityTopic::factory()->create(['community_id' => $community->getKey()]);

        $this->actingAs($member)->post(route('communityTopic.comment.store', $topic), [
            'body' => 'Reply with a pic',
            'images' => [UploadedFile::fake()->image('c.png', 20, 20)],
        ])->assertRedirect(route('communityTopic.show', $topic));

        $comment = $topic->comments()->firstOrFail();
        $file = $comment->images()->with('file')->first()->file;
        $this->assertSame('communityTopicComment', $file->related_entity_type);
        $this->assertSame($comment->getKey(), $file->related_entity_id);
    }

    public function test_a_members_only_boards_topic_image_is_private_to_non_members(): void
    {
        $community = Community::factory()->create(['topic_read_access' => TopicReadAccess::MembersOnly]);
        $member = $this->joined($community);
        $topic = app(CreateTopic::class)($member, $community, $this->form(), [UploadedFile::fake()->image('x.png', 20, 20)]);
        $file = $topic->images()->with('file')->first()->file;

        // Inherits the board's read access: a stranger is denied (404, never 403), a member may fetch.
        $this->actingAs(Member::factory()->create())->get($file->url())->assertNotFound();
        $this->actingAs($member)->get($file->url())->assertOk();
    }

    public function test_deleting_a_topic_purges_its_and_its_comments_image_bytes(): void
    {
        $community = Community::factory()->create();
        $author = $this->joined($community);
        $topic = app(CreateTopic::class)($author, $community, $this->form(), [UploadedFile::fake()->image('t.png', 20, 20)]);
        app(CreateTopicComment::class)($author, $topic, 'reply', [UploadedFile::fake()->image('c.png', 20, 20)]);

        $topicFile = $topic->images()->with('file')->first()->file;
        $commentFile = $topic->comments()->firstOrFail()->images()->with('file')->first()->file;

        app(DeleteTopic::class)($author, $topic->fresh());

        // The link rows go with the cascade; the owned File bytes are purged explicitly.
        $this->assertNull(File::find($topicFile->getKey()));
        $this->assertNull(File::find($commentFile->getKey()));
        $this->assertSame(0, DB::table('file_bin')->whereIn('file_id', [$topicFile->getKey(), $commentFile->getKey()])->count());
        $this->assertDatabaseCount('community_topic_images', 0);
        $this->assertDatabaseCount('community_topic_comment_images', 0);
    }

    public function test_deleting_a_comment_purges_its_image_bytes(): void
    {
        $community = Community::factory()->create();
        $author = $this->joined($community);
        $topic = CommunityTopic::factory()->create(['community_id' => $community->getKey(), 'member_id' => $author->getKey()]);
        $comment = app(CreateTopicComment::class)($author, $topic, 'reply', [UploadedFile::fake()->image('c.png', 20, 20)]);
        $file = $comment->images()->with('file')->first()->file;

        app(DeleteTopicComment::class)($author, $comment->fresh());

        $this->assertNull(File::find($file->getKey()));
        $this->assertSame(0, DB::table('file_bin')->where('file_id', $file->getKey())->count());
    }

    public function test_more_than_three_images_are_rejected(): void
    {
        $community = Community::factory()->create();
        $member = $this->joined($community);

        $this->actingAs($member)->post(route('communityTopic.store', $community), [
            'name' => 'too many',
            'body' => 'b',
            'images' => [
                UploadedFile::fake()->image('1.png', 10, 10),
                UploadedFile::fake()->image('2.png', 10, 10),
                UploadedFile::fake()->image('3.png', 10, 10),
                UploadedFile::fake()->image('4.png', 10, 10),
            ],
        ])->assertSessionHasErrors('images');

        $this->assertDatabaseCount('community_topics', 0);
    }

    public function test_a_non_image_attachment_is_rejected(): void
    {
        $community = Community::factory()->create();
        $member = $this->joined($community);

        $this->actingAs($member)->post(route('communityTopic.store', $community), [
            'name' => 'bad file',
            'body' => 'b',
            'images' => [UploadedFile::fake()->create('notes.txt', 10, 'text/plain')],
        ])->assertSessionHasErrors('images.0');

        $this->assertDatabaseCount('community_topics', 0);
    }

    public function test_a_failed_later_image_compensates_the_earlier_images_bytes(): void
    {
        // Drive a disk backend so writeStream lands real bytes, then fail the second write. The
        // first image's bytes must be compensated off the disk when the outer transaction rolls
        // back — a disk write is not transactional, so without compensation it would orphan.
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

        $community = Community::factory()->create();
        $member = $this->joined($community);

        try {
            app(CreateTopic::class)($member, $community, $this->form(), [
                UploadedFile::fake()->image('1.png', 20, 20),
                UploadedFile::fake()->image('2.png', 20, 20),
            ]);
            $this->fail('expected the failed image store to throw');
        } catch (RuntimeException) {
            // expected
        }

        // The transaction rolled back wholesale: no topic, no File rows, no link rows.
        $this->assertDatabaseCount('community_topics', 0);
        $this->assertDatabaseCount('files', 0);
        $this->assertDatabaseCount('community_topic_images', 0);
        // And the first image's bytes were compensated — no orphan left on disk.
        $this->assertEmpty(Storage::disk('local')->allFiles());
    }
}
