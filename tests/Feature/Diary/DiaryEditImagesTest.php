<?php

namespace Tests\Feature\Diary;

use App\Features\Diary\Actions\CreateDiary;
use App\Features\Diary\Actions\UpdateDiary;
use App\Features\Diary\Data\DiaryFormData;
use App\Features\Diary\Exceptions\DiaryActionException;
use App\Files\DiskFileStorage;
use App\Files\FileStorage;
use App\Models\Diary;
use App\Models\DiaryImage;
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

class DiaryEditImagesTest extends TestCase
{
    use RefreshDatabase;

    private function fake(string $name = 'i.png'): UploadedFile
    {
        return UploadedFile::fake()->image($name, 20, 20);
    }

    /** @param  array<int, UploadedFile>  $images */
    private function diaryWith(Member $author, array $images): Diary
    {
        return app(CreateDiary::class)($author, new DiaryFormData('Title', 'Body', Visibility::Members), $images);
    }

    /** @return array<string, mixed> */
    private function payload(Diary $diary, array $extra = []): array
    {
        return array_merge([
            'title' => $diary->title,
            'body' => $diary->body,
            'visibility' => $diary->visibility->value,
        ], $extra);
    }

    public function test_the_edit_form_lists_current_images_with_remove_checkboxes(): void
    {
        $author = Member::factory()->create();
        $diary = $this->diaryWith($author, [$this->fake()]);
        $image = $diary->images()->with('file')->first();

        $this->actingAs($author)->get(route('diary.edit', $diary))
            ->assertOk()
            ->assertSee($image->file->thumbnailUrl(120, 120, square: true), escape: false)
            ->assertSee('name="remove_images[]"', escape: false)
            ->assertSee('value="'.$image->id.'"', escape: false);
    }

    public function test_an_added_image_fills_the_next_free_slot(): void
    {
        $author = Member::factory()->create();
        $diary = $this->diaryWith($author, [$this->fake('a.png')]); // slot 1

        $this->actingAs($author)->post(route('diary.update', $diary), $this->payload($diary, [
            'images' => [$this->fake('b.png')],
        ]))->assertRedirect(route('diary.show', $diary));

        $this->assertSame([1, 2], $diary->fresh()->images()->pluck('number')->all());
    }

    public function test_removing_an_image_drops_the_row_and_purges_its_bytes(): void
    {
        $author = Member::factory()->create();
        $diary = $this->diaryWith($author, [$this->fake('a.png'), $this->fake('b.png')]); // slots 1,2
        $image1 = $diary->images()->where('number', 1)->with('file')->first();
        $file1 = $image1->file;

        $this->actingAs($author)->post(route('diary.update', $diary), $this->payload($diary, [
            'remove_images' => [$image1->id],
        ]))->assertRedirect(route('diary.show', $diary));

        $this->assertNull(DiaryImage::find($image1->id));
        $this->assertNull(File::find($file1->getKey()));
        $this->assertSame(0, DB::table('file_bin')->where('file_id', $file1->getKey())->count());
        $this->assertSame([2], $diary->fresh()->images()->pluck('number')->all());
    }

    public function test_keeping_plus_adding_beyond_the_cap_is_rejected(): void
    {
        $author = Member::factory()->create();
        $diary = $this->diaryWith($author, [$this->fake('1.png'), $this->fake('2.png'), $this->fake('3.png')]); // full

        $this->actingAs($author)->post(route('diary.update', $diary), $this->payload($diary, [
            'title' => 'Edited',
            'images' => [$this->fake('4.png')], // no removals → would be 4
        ]))->assertSessionHasErrors('images');

        $this->assertSame('Title', $diary->fresh()->title); // validation failed before the edit applied
        $this->assertSame(3, $diary->images()->count());
    }

    public function test_duplicate_remove_ids_cannot_bypass_the_image_cap(): void
    {
        $author = Member::factory()->create();
        $diary = $this->diaryWith($author, [$this->fake('1.png'), $this->fake('2.png'), $this->fake('3.png')]);
        $first = $diary->images()->where('number', 1)->first();

        // remove_images=[id, id] must not count one removal twice (kept would read as 1, letting two
        // new images through to 3) — the cap check dedupes, so this stays over cap.
        $this->actingAs($author)->post(route('diary.update', $diary), $this->payload($diary, [
            'remove_images' => [$first->id, $first->id],
            'images' => [$this->fake('a.png'), $this->fake('b.png')],
        ]))->assertSessionHasErrors('images');

        $this->assertSame(3, $diary->images()->count());
    }

    public function test_a_remove_id_from_another_diary_is_ignored(): void
    {
        $author = Member::factory()->create();
        $diary = $this->diaryWith($author, [$this->fake('mine.png')]);
        $other = $this->diaryWith($author, [$this->fake('theirs.png')]);
        $otherImage = $other->images()->first();

        $this->actingAs($author)->post(route('diary.update', $diary), $this->payload($diary, [
            'remove_images' => [$otherImage->id],
        ]))->assertRedirect(route('diary.show', $diary));

        $this->assertNotNull(DiaryImage::find($otherImage->id));
        $this->assertSame(1, $diary->images()->count());
    }

    public function test_the_action_refuses_more_new_images_than_free_slots(): void
    {
        $author = Member::factory()->create();
        $diary = $this->diaryWith($author, [$this->fake('a.png'), $this->fake('b.png')]); // slots 1,2; one free

        // Concurrency-race backstop: more uploads than free slots fails cleanly rather than indexing
        // past the free-slot list.
        $this->expectException(DiaryActionException::class);
        app(UpdateDiary::class)($author, $diary, new DiaryFormData('Title', 'Body', Visibility::Members), [$this->fake('c.png'), $this->fake('d.png')], []);
    }

    public function test_a_failed_added_image_rolls_back_the_removal_and_leaves_no_orphan(): void
    {
        config(['openpne.files.disk' => 'local']);
        Storage::fake('local');

        $author = Member::factory()->create();
        $diary = $this->diaryWith($author, [$this->fake('keep.png')]); // slot 1, bytes on the fake disk
        $image1 = $diary->images()->with('file')->first();
        $file1 = $image1->file;

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

        try {
            app(UpdateDiary::class)($author, $diary->fresh(), new DiaryFormData('Title', 'Body', Visibility::Members), [$this->fake('a.png'), $this->fake('b.png')], [$image1->id]);
            $this->fail('expected the failed image store to throw');
        } catch (RuntimeException) {
            // expected
        }

        // The removal rolled back: the original image and its bytes survive, no new image was added.
        $this->assertNotNull(DiaryImage::find($image1->id));
        $this->assertSame(1, $diary->fresh()->images()->count());
        $this->assertTrue(Storage::disk('local')->exists($file1->name));
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    public function test_a_text_only_edit_leaves_images_untouched(): void
    {
        $author = Member::factory()->create();
        $diary = $this->diaryWith($author, [$this->fake('a.png')]); // slot 1

        $this->actingAs($author)->post(route('diary.update', $diary), $this->payload($diary, [
            'title' => 'Renamed',
        ]))->assertRedirect(route('diary.show', $diary));

        $this->assertSame('Renamed', $diary->fresh()->title);
        $this->assertSame([1], $diary->fresh()->images()->pluck('number')->all());
    }
}
