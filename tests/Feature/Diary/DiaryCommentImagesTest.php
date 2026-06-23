<?php

namespace Tests\Feature\Diary;

use App\Features\Diary\Actions\CreateComment;
use App\Features\Diary\Actions\CreateDiary;
use App\Features\Diary\Actions\DeleteComment;
use App\Features\Diary\Actions\DeleteDiary;
use App\Features\Diary\Data\DiaryFormData;
use App\Files\DiskFileStorage;
use App\Files\FileStorage;
use App\Models\Diary;
use App\Models\File;
use App\Models\Member;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class DiaryCommentImagesTest extends TestCase
{
    use RefreshDatabase;

    private function fake(string $name = 'i.png'): UploadedFile
    {
        return UploadedFile::fake()->image($name, 20, 20);
    }

    private function diary(Member $owner, Visibility $visibility = Visibility::Members): Diary
    {
        return app(CreateDiary::class)($owner, new DiaryFormData('Title', 'Body', $visibility), []);
    }

    public function test_a_comment_is_posted_with_images_owned_by_the_comment_and_shown(): void
    {
        $owner = Member::factory()->create();
        $diary = $this->diary($owner);
        $commenter = Member::factory()->create();

        $this->actingAs($commenter)->post(route('diary.comment.store', $diary), [
            'body' => 'Reply with a pic',
            'images' => [$this->fake('a.png'), $this->fake('b.png')],
        ])->assertRedirect(route('diary.show', $diary));

        $comment = $diary->comments()->firstOrFail();
        $this->assertSame(2, $comment->images()->count());

        $file = $comment->images()->with('file')->first()->file;
        $this->assertSame('diaryComment', $file->related_entity_type);
        $this->assertSame($comment->getKey(), $file->related_entity_id);

        $this->actingAs($owner)->get(route('diary.show', $diary))
            ->assertOk()
            ->assertSee($file->thumbnailUrl(120, 120, square: true), escape: false);
    }

    public function test_a_comment_image_inherits_the_diary_visibility(): void
    {
        // A Members diary: the comment image is visible to any member, but not a blocked one.
        $owner = Member::factory()->create();
        $diary = $this->diary($owner, Visibility::Members);
        $comment = app(CreateComment::class)($owner, $diary, 'body', [$this->fake()]);
        $file = $comment->images()->with('file')->first()->file;

        $this->actingAs(Member::factory()->create())->get($file->url())->assertOk();

        $blocked = Member::factory()->create();
        DB::table('member_blocks')->insert(['blocker_id' => $owner->getKey(), 'blocked_id' => $blocked->getKey()]);
        $this->actingAs($blocked)->get($file->url())->assertNotFound();
    }

    public function test_a_private_diary_comment_image_is_visible_only_to_the_owner(): void
    {
        $owner = Member::factory()->create();
        $diary = $this->diary($owner, Visibility::Private);
        $comment = app(CreateComment::class)($owner, $diary, 'body', [$this->fake()]);
        $file = $comment->images()->with('file')->first()->file;

        $this->actingAs(Member::factory()->create())->get($file->url())->assertNotFound();
        $this->actingAs($owner)->get($file->url())->assertOk();
    }

    public function test_deleting_a_comment_purges_its_image_bytes(): void
    {
        $owner = Member::factory()->create();
        $diary = $this->diary($owner);
        $comment = app(CreateComment::class)($owner, $diary, 'body', [$this->fake()]);
        $file = $comment->images()->with('file')->first()->file;

        app(DeleteComment::class)($owner, $comment->fresh());

        $this->assertNull(File::find($file->getKey()));
        $this->assertSame(0, DB::table('file_bin')->where('file_id', $file->getKey())->count());
        $this->assertDatabaseCount('diary_comment_images', 0);
    }

    public function test_deleting_a_diary_purges_its_comments_image_bytes(): void
    {
        $owner = Member::factory()->create();
        $diary = $this->diary($owner);
        $comment = app(CreateComment::class)($owner, $diary, 'body', [$this->fake('c.png')]);
        $commentFile = $comment->images()->with('file')->first()->file;

        app(DeleteDiary::class)($owner, $diary->fresh());

        $this->assertNull(File::find($commentFile->getKey()));
        $this->assertSame(0, DB::table('file_bin')->where('file_id', $commentFile->getKey())->count());
        $this->assertDatabaseCount('diary_comment_images', 0);
    }

    public function test_more_than_three_images_are_rejected(): void
    {
        $owner = Member::factory()->create();
        $diary = $this->diary($owner);

        $this->actingAs($owner)->post(route('diary.comment.store', $diary), [
            'body' => 'too many',
            'images' => [$this->fake('1.png'), $this->fake('2.png'), $this->fake('3.png'), $this->fake('4.png')],
        ])->assertSessionHasErrors('images');

        $this->assertDatabaseCount('diary_comments', 0);
    }

    public function test_a_non_image_attachment_is_rejected(): void
    {
        $owner = Member::factory()->create();
        $diary = $this->diary($owner);

        $this->actingAs($owner)->post(route('diary.comment.store', $diary), [
            'body' => 'bad file',
            'images' => [UploadedFile::fake()->create('notes.txt', 10, 'text/plain')],
        ])->assertSessionHasErrors('images.0');

        $this->assertDatabaseCount('diary_comments', 0);
    }

    public function test_a_failed_later_image_compensates_the_earlier_images_bytes(): void
    {
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

        $owner = Member::factory()->create();
        $diary = $this->diary($owner);

        try {
            app(CreateComment::class)($owner, $diary, 'body', [$this->fake('1.png'), $this->fake('2.png')]);
            $this->fail('expected the failed image store to throw');
        } catch (RuntimeException) {
            // expected
        }

        // The transaction rolled back wholesale: no comment, no File rows, no link rows, no orphan.
        $this->assertDatabaseCount('diary_comments', 0);
        $this->assertDatabaseCount('files', 0);
        $this->assertDatabaseCount('diary_comment_images', 0);
        $this->assertEmpty(Storage::disk('local')->allFiles());
    }
}
