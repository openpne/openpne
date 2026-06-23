<?php

namespace Tests\Feature\Diary;

use App\Features\Diary\Actions\CreateDiary;
use App\Features\Diary\Actions\DeleteDiary;
use App\Features\Diary\Data\DiaryFormData;
use App\Features\Diary\DiaryAccess;
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

class DiaryImagesTest extends TestCase
{
    use RefreshDatabase;

    private function fake(string $name = 'i.png'): UploadedFile
    {
        return UploadedFile::fake()->image($name, 20, 20);
    }

    /** @param  array<int, UploadedFile>  $images */
    private function diaryWith(Member $author, array $images, Visibility $visibility = Visibility::Members): Diary
    {
        return app(CreateDiary::class)($author, new DiaryFormData('Title', 'Body', $visibility), $images);
    }

    private function makeFriends(Member $a, Member $b): void
    {
        DB::table('friendships')->insert([
            ['member_id' => $a->getKey(), 'friend_id' => $b->getKey()],
            ['member_id' => $b->getKey(), 'friend_id' => $a->getKey()],
        ]);
    }

    public function test_a_diary_is_created_with_numbered_images_it_owns_and_shows_them(): void
    {
        $author = Member::factory()->create();

        $this->actingAs($author)->post(route('diary.store'), [
            'title' => 'With pics',
            'body' => 'See attached.',
            'visibility' => Visibility::Members->value,
            'images' => [$this->fake('a.png'), $this->fake('b.png')],
        ])->assertRedirect();

        $diary = Diary::where('title', 'With pics')->firstOrFail();
        $this->assertSame([1, 2], $diary->images()->pluck('number')->all());

        $file = $diary->images()->with('file')->first()->file;
        // The image File is owned by the diary, the source of its visibility.
        $this->assertSame('diary', $file->related_entity_type);
        $this->assertSame($diary->getKey(), $file->related_entity_id);

        $this->actingAs($author)->get(route('diary.show', $diary))
            ->assertOk()
            ->assertSee($file->thumbnailUrl(120, 120, square: true), escape: false);
    }

    public function test_a_members_diary_image_is_visible_to_any_member_but_not_a_blocked_one(): void
    {
        $owner = Member::factory()->create();
        $diary = $this->diaryWith($owner, [$this->fake()], Visibility::Members);
        $file = $diary->images()->with('file')->first()->file;

        $this->actingAs(Member::factory()->create())->get($file->url())->assertOk();

        $blocked = Member::factory()->create();
        DB::table('member_blocks')->insert(['blocker_id' => $owner->getKey(), 'blocked_id' => $blocked->getKey()]);
        $this->actingAs($blocked)->get($file->url())->assertNotFound();
    }

    public function test_a_friends_diary_image_is_private_to_non_friends(): void
    {
        $owner = Member::factory()->create();
        $diary = $this->diaryWith($owner, [$this->fake()], Visibility::Friends);
        $file = $diary->images()->with('file')->first()->file;

        // A stranger is denied (404, never 403); a friend and the owner may fetch.
        $this->actingAs(Member::factory()->create())->get($file->url())->assertNotFound();

        $friend = Member::factory()->create();
        $this->makeFriends($owner, $friend);
        $this->actingAs($friend)->get($file->url())->assertOk();
        $this->actingAs($owner)->get($file->url())->assertOk();
    }

    public function test_a_web_public_diary_is_readable_by_a_guest_but_a_members_one_is_not(): void
    {
        // The file/image delivery routes are auth-gated, so the guest path is exercised at the
        // policy boundary: an Open (web-public) diary's image is guest-readable, a Members one is not.
        $owner = Member::factory()->create();
        $open = $this->diaryWith($owner, [$this->fake()], Visibility::Open);
        $members = $this->diaryWith($owner, [$this->fake()], Visibility::Members);

        $this->assertTrue(DiaryAccess::canView(null, $open));
        $this->assertFalse(DiaryAccess::canView(null, $members));
    }

    public function test_a_private_diary_image_is_visible_only_to_the_owner(): void
    {
        $owner = Member::factory()->create();
        $diary = $this->diaryWith($owner, [$this->fake()], Visibility::Private);
        $file = $diary->images()->with('file')->first()->file;

        $this->actingAs(Member::factory()->create())->get($file->url())->assertNotFound();
        $this->actingAs($owner)->get($file->url())->assertOk();
    }

    public function test_deleting_a_diary_purges_its_image_bytes(): void
    {
        $owner = Member::factory()->create();
        $diary = $this->diaryWith($owner, [$this->fake('a.png'), $this->fake('b.png')]);
        $files = $diary->images()->with('file')->get()->pluck('file');

        app(DeleteDiary::class)($owner, $diary->fresh());

        foreach ($files as $file) {
            $this->assertNull(File::find($file->getKey()));
            $this->assertSame(0, DB::table('file_bin')->where('file_id', $file->getKey())->count());
        }
        $this->assertDatabaseCount('diary_images', 0);
    }

    public function test_more_than_three_images_are_rejected(): void
    {
        $author = Member::factory()->create();

        $this->actingAs($author)->post(route('diary.store'), [
            'title' => 'too many',
            'body' => 'b',
            'visibility' => Visibility::Members->value,
            'images' => [$this->fake('1.png'), $this->fake('2.png'), $this->fake('3.png'), $this->fake('4.png')],
        ])->assertSessionHasErrors('images');

        $this->assertDatabaseCount('diaries', 0);
    }

    public function test_a_non_image_attachment_is_rejected(): void
    {
        $author = Member::factory()->create();

        $this->actingAs($author)->post(route('diary.store'), [
            'title' => 'bad file',
            'body' => 'b',
            'visibility' => Visibility::Members->value,
            'images' => [UploadedFile::fake()->create('notes.txt', 10, 'text/plain')],
        ])->assertSessionHasErrors('images.0');

        $this->assertDatabaseCount('diaries', 0);
    }

    public function test_the_feed_shows_a_has_images_marker_only_for_entries_with_images(): void
    {
        $author = Member::factory()->create();
        $this->diaryWith($author, [$this->fake()], Visibility::Members);
        $this->diaryWith($author, [], Visibility::Members);

        $response = $this->actingAs(Member::factory()->create())->get(route('diary.list'))->assertOk();
        // Exactly one of the two entries carries the marker.
        $this->assertSame(1, substr_count($response->getContent(), 'class="imageIcon"'));
    }

    public function test_a_failed_later_image_compensates_the_earlier_images_bytes(): void
    {
        // A disk write is not transactional: when the second write fails and the outer transaction
        // rolls back, the first image's bytes must be compensated off the disk rather than orphaned.
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

        $author = Member::factory()->create();

        try {
            $this->diaryWith($author, [$this->fake('1.png'), $this->fake('2.png')]);
            $this->fail('expected the failed image store to throw');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertDatabaseCount('diaries', 0);
        $this->assertDatabaseCount('files', 0);
        $this->assertDatabaseCount('diary_images', 0);
        $this->assertEmpty(Storage::disk('local')->allFiles());
    }
}
