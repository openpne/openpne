<?php

namespace Tests\Feature\CommunityTopic;

use App\Features\Community\CommunityRole;
use App\Features\CommunityTopic\Actions\CreateTopic;
use App\Features\CommunityTopic\Actions\UpdateTopic;
use App\Features\CommunityTopic\Data\CommunityTopicFormData;
use App\Files\DiskFileStorage;
use App\Files\FileStorage;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\CommunityTopic;
use App\Models\CommunityTopicImage;
use App\Models\File;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class CommunityTopicEditImagesTest extends TestCase
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

    /** @param  array<int, UploadedFile>  $images */
    private function topicWith(Community $community, Member $author, array $images): CommunityTopic
    {
        return app(CreateTopic::class)($author, $community, $this->form(), $images);
    }

    private function fake(string $name = 'i.png'): UploadedFile
    {
        return UploadedFile::fake()->image($name, 20, 20);
    }

    public function test_the_edit_form_lists_current_images_with_remove_checkboxes(): void
    {
        $community = Community::factory()->create();
        $author = $this->joined($community);
        $topic = $this->topicWith($community, $author, [$this->fake()]);
        $image = $topic->images()->with('file')->first();

        $this->actingAs($author)->get(route('communityTopic.edit', $topic))
            ->assertOk()
            ->assertSee($image->file->thumbnailUrl(120, 120, square: true), escape: false)
            ->assertSee('name="remove_images[]"', escape: false)
            ->assertSee('value="'.$image->id.'"', escape: false);
    }

    public function test_an_added_image_fills_the_next_free_slot(): void
    {
        $community = Community::factory()->create();
        $author = $this->joined($community);
        $topic = $this->topicWith($community, $author, [$this->fake('a.png')]); // slot 1

        $this->actingAs($author)->post(route('communityTopic.update', $topic), [
            'name' => $topic->name,
            'body' => $topic->body,
            'images' => [$this->fake('b.png')],
        ])->assertRedirect(route('communityTopic.show', $topic));

        $this->assertSame([1, 2], $topic->fresh()->images()->pluck('number')->all());
    }

    public function test_removing_an_image_drops_the_row_and_purges_its_bytes(): void
    {
        $community = Community::factory()->create();
        $author = $this->joined($community);
        $topic = $this->topicWith($community, $author, [$this->fake('a.png'), $this->fake('b.png')]); // slots 1,2
        $image1 = $topic->images()->where('number', 1)->with('file')->first();
        $file1 = $image1->file;

        $this->actingAs($author)->post(route('communityTopic.update', $topic), [
            'name' => $topic->name,
            'body' => $topic->body,
            'remove_images' => [$image1->id],
        ])->assertRedirect(route('communityTopic.show', $topic));

        $this->assertNull(CommunityTopicImage::find($image1->id));
        $this->assertNull(File::find($file1->getKey()));
        $this->assertSame(0, DB::table('file_bin')->where('file_id', $file1->getKey())->count());
        // The other image is untouched, keeping its slot.
        $this->assertSame([2], $topic->fresh()->images()->pluck('number')->all());
    }

    public function test_removing_and_adding_in_one_edit_reuses_the_freed_slot(): void
    {
        $community = Community::factory()->create();
        $author = $this->joined($community);
        $topic = $this->topicWith($community, $author, [$this->fake('old.png')]); // slot 1
        $old = $topic->images()->with('file')->first();
        $oldFile = $old->file;

        $this->actingAs($author)->post(route('communityTopic.update', $topic), [
            'name' => $topic->name,
            'body' => $topic->body,
            'remove_images' => [$old->id],
            'images' => [$this->fake('new.png')],
        ])->assertRedirect(route('communityTopic.show', $topic));

        $this->assertNull(File::find($oldFile->getKey()));
        $fresh = $topic->fresh()->images()->with('file')->get();
        $this->assertSame([1], $fresh->pluck('number')->all());
        $this->assertNotSame($oldFile->getKey(), $fresh->first()->file_id);
    }

    public function test_keeping_plus_adding_beyond_the_cap_is_rejected(): void
    {
        $community = Community::factory()->create();
        $author = $this->joined($community);
        $topic = $this->topicWith($community, $author, [$this->fake('1.png'), $this->fake('2.png'), $this->fake('3.png')]); // full

        $this->actingAs($author)->post(route('communityTopic.update', $topic), [
            'name' => 'Edited',
            'body' => $topic->body,
            'images' => [$this->fake('4.png')], // no removals → would be 4
        ])->assertSessionHasErrors('images');

        $this->assertSame('Topic', $topic->fresh()->name); // validation failed before the edit applied
        $this->assertSame(3, $topic->images()->count());
    }

    public function test_a_remove_id_from_another_topic_is_ignored(): void
    {
        $community = Community::factory()->create();
        $author = $this->joined($community);
        $topic = $this->topicWith($community, $author, [$this->fake('mine.png')]);
        $other = $this->topicWith($community, $author, [$this->fake('theirs.png')]);
        $otherImage = $other->images()->first();

        $this->actingAs($author)->post(route('communityTopic.update', $topic), [
            'name' => $topic->name,
            'body' => $topic->body,
            'remove_images' => [$otherImage->id],
        ])->assertRedirect(route('communityTopic.show', $topic));

        // Neither the other topic's image nor this topic's own image was removed.
        $this->assertNotNull(CommunityTopicImage::find($otherImage->id));
        $this->assertSame(1, $topic->images()->count());
    }

    public function test_a_failed_added_image_rolls_back_the_removal_and_leaves_no_orphan(): void
    {
        config(['openpne.files.disk' => 'local']);
        Storage::fake('local');

        $community = Community::factory()->create();
        $author = $this->joined($community);
        $topic = $this->topicWith($community, $author, [$this->fake('keep.png')]); // slot 1, bytes on the fake disk
        $image1 = $topic->images()->with('file')->first();
        $file1 = $image1->file;

        // Fail the second added image's write; delegate the rest to the real disk.
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
            app(UpdateTopic::class)($author, $topic->fresh(), $this->form(), [$this->fake('a.png'), $this->fake('b.png')], [$image1->id]);
            $this->fail('expected the failed image store to throw');
        } catch (RuntimeException) {
            // expected
        }

        // The removal rolled back: the original image and its bytes survive, no new image was added.
        $this->assertNotNull(CommunityTopicImage::find($image1->id));
        $this->assertSame(1, $topic->fresh()->images()->count());
        $this->assertTrue(Storage::disk('local')->exists($file1->name));
        // Only the surviving image's bytes remain — the first added image was compensated.
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    public function test_a_text_only_edit_leaves_images_untouched(): void
    {
        $community = Community::factory()->create();
        $author = $this->joined($community);
        $topic = $this->topicWith($community, $author, [$this->fake('a.png')]); // slot 1

        $this->actingAs($author)->post(route('communityTopic.update', $topic), [
            'name' => 'Renamed',
            'body' => $topic->body,
        ])->assertRedirect(route('communityTopic.show', $topic));

        $this->assertSame('Renamed', $topic->fresh()->name);
        $this->assertSame([1], $topic->fresh()->images()->pluck('number')->all());
    }
}
