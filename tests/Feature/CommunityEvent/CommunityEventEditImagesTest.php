<?php

namespace Tests\Feature\CommunityEvent;

use App\Features\Community\CommunityRole;
use App\Features\CommunityEvent\Actions\CreateEvent;
use App\Features\CommunityEvent\Actions\UpdateEvent;
use App\Features\CommunityEvent\Data\CommunityEventFormData;
use App\Features\CommunityEvent\Exceptions\CommunityEventActionException;
use App\Files\DiskFileStorage;
use App\Files\FileStorage;
use App\Models\Community;
use App\Models\CommunityEvent;
use App\Models\CommunityEventImage;
use App\Models\CommunityMember;
use App\Models\File;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class CommunityEventEditImagesTest extends TestCase
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

    private function form(string $name = 'Event', string $body = 'Body'): CommunityEventFormData
    {
        return new CommunityEventFormData(
            name: $name,
            body: $body,
            open_date: now()->addWeek()->format('Y-m-d'),
            open_date_comment: '19:00-',
            area: 'Shibuya',
            application_deadline: null,
            capacity: null,
        );
    }

    /** Mirror the form's fields as an HTTP payload so an edit round-trips through validation. */
    private function payloadFrom(CommunityEvent $event, array $overrides = []): array
    {
        return array_merge([
            'name' => $event->name,
            'body' => $event->body,
            'open_date' => $event->open_date->format('Y-m-d'),
            'open_date_comment' => (string) $event->open_date_comment,
            'area' => $event->area,
        ], $overrides);
    }

    /** @param  array<int, UploadedFile>  $images */
    private function eventWith(Community $community, Member $author, array $images): CommunityEvent
    {
        return app(CreateEvent::class)($author, $community, $this->form(), $images);
    }

    private function fake(string $name = 'i.png'): UploadedFile
    {
        return UploadedFile::fake()->image($name, 20, 20);
    }

    public function test_the_edit_form_lists_current_images_with_remove_checkboxes(): void
    {
        $community = Community::factory()->create();
        $author = $this->joined($community);
        $event = $this->eventWith($community, $author, [$this->fake()]);
        $image = $event->images()->with('file')->first();

        $this->actingAs($author)->get(route('communityEvent.edit', $event))
            ->assertOk()
            ->assertSee($image->file->thumbnailUrl(120, 120, square: true), escape: false)
            ->assertSee('name="remove_images[]"', escape: false)
            ->assertSee('value="'.$image->id.'"', escape: false);
    }

    public function test_an_added_image_fills_the_next_free_slot(): void
    {
        $community = Community::factory()->create();
        $author = $this->joined($community);
        $event = $this->eventWith($community, $author, [$this->fake('a.png')]); // slot 1

        $this->actingAs($author)->post(route('communityEvent.update', $event), $this->payloadFrom($event, [
            'images' => [$this->fake('b.png')],
        ]))->assertRedirect(route('communityEvent.show', $event));

        $this->assertSame([1, 2], $event->fresh()->images()->pluck('number')->all());
    }

    public function test_removing_an_image_drops_the_row_and_purges_its_bytes(): void
    {
        $community = Community::factory()->create();
        $author = $this->joined($community);
        $event = $this->eventWith($community, $author, [$this->fake('a.png'), $this->fake('b.png')]); // slots 1,2
        $image1 = $event->images()->where('number', 1)->with('file')->first();
        $file1 = $image1->file;

        $this->actingAs($author)->post(route('communityEvent.update', $event), $this->payloadFrom($event, [
            'remove_images' => [$image1->id],
        ]))->assertRedirect(route('communityEvent.show', $event));

        $this->assertNull(CommunityEventImage::find($image1->id));
        $this->assertNull(File::find($file1->getKey()));
        $this->assertSame(0, DB::table('file_bin')->where('file_id', $file1->getKey())->count());
        // The other image is untouched, keeping its slot.
        $this->assertSame([2], $event->fresh()->images()->pluck('number')->all());
    }

    public function test_removing_and_adding_in_one_edit_reuses_the_freed_slot(): void
    {
        $community = Community::factory()->create();
        $author = $this->joined($community);
        $event = $this->eventWith($community, $author, [$this->fake('old.png')]); // slot 1
        $old = $event->images()->with('file')->first();
        $oldFile = $old->file;

        $this->actingAs($author)->post(route('communityEvent.update', $event), $this->payloadFrom($event, [
            'remove_images' => [$old->id],
            'images' => [$this->fake('new.png')],
        ]))->assertRedirect(route('communityEvent.show', $event));

        $this->assertNull(File::find($oldFile->getKey()));
        $fresh = $event->fresh()->images()->with('file')->get();
        $this->assertSame([1], $fresh->pluck('number')->all());
        $this->assertNotSame($oldFile->getKey(), $fresh->first()->file_id);
    }

    public function test_keeping_plus_adding_beyond_the_cap_is_rejected(): void
    {
        $community = Community::factory()->create();
        $author = $this->joined($community);
        $event = $this->eventWith($community, $author, [$this->fake('1.png'), $this->fake('2.png'), $this->fake('3.png')]); // full

        $this->actingAs($author)->post(route('communityEvent.update', $event), $this->payloadFrom($event, [
            'name' => 'Edited',
            'images' => [$this->fake('4.png')], // no removals → would be 4
        ]))->assertSessionHasErrors('images');

        $this->assertSame('Event', $event->fresh()->name); // validation failed before the edit applied
        $this->assertSame(3, $event->images()->count());
    }

    public function test_duplicate_remove_ids_cannot_bypass_the_image_cap(): void
    {
        $community = Community::factory()->create();
        $author = $this->joined($community);
        $event = $this->eventWith($community, $author, [$this->fake('1.png'), $this->fake('2.png'), $this->fake('3.png')]);
        $first = $event->images()->where('number', 1)->first();

        // A crafted remove_images=[id, id] must not count one image's removal twice — the cap check
        // dedupes, so this stays over cap.
        $this->actingAs($author)->post(route('communityEvent.update', $event), $this->payloadFrom($event, [
            'remove_images' => [$first->id, $first->id],
            'images' => [$this->fake('a.png'), $this->fake('b.png')],
        ]))->assertSessionHasErrors('images');

        $this->assertSame(3, $event->images()->count());
    }

    public function test_the_action_refuses_more_new_images_than_free_slots(): void
    {
        $community = Community::factory()->create();
        $author = $this->joined($community);
        $event = $this->eventWith($community, $author, [$this->fake('a.png'), $this->fake('b.png')]); // slots 1,2; one free

        // Backstop against a lost concurrency race: more uploads than free slots fails cleanly
        // instead of indexing past the free-slot list.
        $this->expectException(CommunityEventActionException::class);
        app(UpdateEvent::class)($author, $event, $this->form(), [$this->fake('c.png'), $this->fake('d.png')], []);
    }

    public function test_a_remove_id_from_another_event_is_ignored(): void
    {
        $community = Community::factory()->create();
        $author = $this->joined($community);
        $event = $this->eventWith($community, $author, [$this->fake('mine.png')]);
        $other = $this->eventWith($community, $author, [$this->fake('theirs.png')]);
        $otherImage = $other->images()->first();

        $this->actingAs($author)->post(route('communityEvent.update', $event), $this->payloadFrom($event, [
            'remove_images' => [$otherImage->id],
        ]))->assertRedirect(route('communityEvent.show', $event));

        // Neither the other event's image nor this event's own image was removed.
        $this->assertNotNull(CommunityEventImage::find($otherImage->id));
        $this->assertSame(1, $event->images()->count());
    }

    public function test_a_failed_added_image_rolls_back_the_removal_and_leaves_no_orphan(): void
    {
        config(['openpne.files.disk' => 'local']);
        Storage::fake('local');

        $community = Community::factory()->create();
        $author = $this->joined($community);
        $event = $this->eventWith($community, $author, [$this->fake('keep.png')]); // slot 1, bytes on the fake disk
        $image1 = $event->images()->with('file')->first();
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
            app(UpdateEvent::class)($author, $event->fresh(), $this->form(), [$this->fake('a.png'), $this->fake('b.png')], [$image1->id]);
            $this->fail('expected the failed image store to throw');
        } catch (RuntimeException) {
            // expected
        }

        // The removal rolled back: the original image and its bytes survive, no new image was added.
        $this->assertNotNull(CommunityEventImage::find($image1->id));
        $this->assertSame(1, $event->fresh()->images()->count());
        $this->assertTrue(Storage::disk('local')->exists($file1->name));
        // Only the surviving image's bytes remain — the first added image was compensated.
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    public function test_a_text_only_edit_leaves_images_untouched(): void
    {
        $community = Community::factory()->create();
        $author = $this->joined($community);
        $event = $this->eventWith($community, $author, [$this->fake('a.png')]); // slot 1

        $this->actingAs($author)->post(route('communityEvent.update', $event), $this->payloadFrom($event, [
            'name' => 'Renamed',
        ]))->assertRedirect(route('communityEvent.show', $event));

        $this->assertSame('Renamed', $event->fresh()->name);
        $this->assertSame([1], $event->fresh()->images()->pluck('number')->all());
    }
}
