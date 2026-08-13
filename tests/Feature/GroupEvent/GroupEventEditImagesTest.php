<?php

namespace Tests\Feature\GroupEvent;

use App\Features\Group\GroupRole;
use App\Features\GroupEvent\Actions\CreateEvent;
use App\Features\GroupEvent\Actions\UpdateEvent;
use App\Features\GroupEvent\Data\GroupEventFormData;
use App\Features\GroupEvent\Exceptions\GroupEventActionException;
use App\Files\DiskFileStorage;
use App\Files\FileStorage;
use App\Files\ImageEdit;
use App\Models\File;
use App\Models\Group;
use App\Models\GroupEvent;
use App\Models\GroupEventImage;
use App\Models\GroupMember;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class GroupEventEditImagesTest extends TestCase
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

    private function form(string $name = 'Event', string $body = 'Body'): GroupEventFormData
    {
        return new GroupEventFormData(
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
    private function payloadFrom(GroupEvent $event, array $overrides = []): array
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
    private function eventWith(Group $group, Member $author, array $images): GroupEvent
    {
        return app(CreateEvent::class)($author, $group, $this->form(), $images);
    }

    private function fake(string $name = 'i.png'): UploadedFile
    {
        return UploadedFile::fake()->image($name, 20, 20);
    }

    public function test_the_edit_form_lists_current_images_with_remove_checkboxes(): void
    {
        $group = Group::factory()->create();
        $author = $this->joined($group);
        $event = $this->eventWith($group, $author, [$this->fake()]);
        $image = $event->images()->with('file')->first();

        $this->actingAs($author)->get(route('group.events.edit', $event))
            ->assertOk()
            ->assertSee($image->file->thumbnailUrl(120, 120, square: true), escape: false)
            ->assertSee('name="remove_images[]"', escape: false)
            ->assertSee('value="'.$image->id.'"', escape: false);
    }

    public function test_an_added_image_fills_the_next_free_slot(): void
    {
        $group = Group::factory()->create();
        $author = $this->joined($group);
        $event = $this->eventWith($group, $author, [$this->fake('a.png')]); // slot 1

        $this->actingAs($author)->post(route('group.events.update', $event), $this->payloadFrom($event, [
            'images' => [$this->fake('b.png')],
        ]))->assertRedirect(route('group.events.show', $event));

        $this->assertSame([1, 2], $event->fresh()->images()->pluck('number')->all());
    }

    public function test_removing_an_image_drops_the_row_and_purges_its_bytes(): void
    {
        $group = Group::factory()->create();
        $author = $this->joined($group);
        $event = $this->eventWith($group, $author, [$this->fake('a.png'), $this->fake('b.png')]); // slots 1,2
        $image1 = $event->images()->where('number', 1)->with('file')->first();
        $file1 = $image1->file;

        $this->actingAs($author)->post(route('group.events.update', $event), $this->payloadFrom($event, [
            'remove_images' => [$image1->id],
        ]))->assertRedirect(route('group.events.show', $event));

        $this->assertNull(GroupEventImage::find($image1->id));
        $this->assertNull(File::find($file1->getKey()));
        $this->assertSame(0, DB::table('file_bin')->where('file_id', $file1->getKey())->count());
        // The other image is untouched, keeping its slot.
        $this->assertSame([2], $event->fresh()->images()->pluck('number')->all());
    }

    public function test_removing_and_adding_in_one_edit_reuses_the_freed_slot(): void
    {
        $group = Group::factory()->create();
        $author = $this->joined($group);
        $event = $this->eventWith($group, $author, [$this->fake('old.png')]); // slot 1
        $old = $event->images()->with('file')->first();
        $oldFile = $old->file;

        $this->actingAs($author)->post(route('group.events.update', $event), $this->payloadFrom($event, [
            'remove_images' => [$old->id],
            'images' => [$this->fake('new.png')],
        ]))->assertRedirect(route('group.events.show', $event));

        $this->assertNull(File::find($oldFile->getKey()));
        $fresh = $event->fresh()->images()->with('file')->get();
        $this->assertSame([1], $fresh->pluck('number')->all());
        $this->assertNotSame($oldFile->getKey(), $fresh->first()->file_id);
    }

    public function test_keeping_plus_adding_beyond_the_cap_is_rejected(): void
    {
        $group = Group::factory()->create();
        $author = $this->joined($group);
        $event = $this->eventWith($group, $author, [$this->fake('1.png'), $this->fake('2.png'), $this->fake('3.png')]); // full

        $this->actingAs($author)->post(route('group.events.update', $event), $this->payloadFrom($event, [
            'name' => 'Edited',
            'images' => [$this->fake('4.png')], // no removals → would be 4
        ]))->assertSessionHasErrors('images');

        $this->assertSame('Event', $event->fresh()->name); // validation failed before the edit applied
        $this->assertSame(3, $event->images()->count());
    }

    public function test_duplicate_remove_ids_cannot_bypass_the_image_cap(): void
    {
        $group = Group::factory()->create();
        $author = $this->joined($group);
        $event = $this->eventWith($group, $author, [$this->fake('1.png'), $this->fake('2.png'), $this->fake('3.png')]);
        $first = $event->images()->where('number', 1)->first();

        // A crafted remove_images=[id, id] must not count one image's removal twice — the cap check
        // dedupes, so this stays over cap.
        $this->actingAs($author)->post(route('group.events.update', $event), $this->payloadFrom($event, [
            'remove_images' => [$first->id, $first->id],
            'images' => [$this->fake('a.png'), $this->fake('b.png')],
        ]))->assertSessionHasErrors('images');

        $this->assertSame(3, $event->images()->count());
    }

    public function test_the_action_refuses_more_new_images_than_free_slots(): void
    {
        $group = Group::factory()->create();
        $author = $this->joined($group);
        $event = $this->eventWith($group, $author, [$this->fake('a.png'), $this->fake('b.png')]); // slots 1,2; one free

        // Backstop against a lost concurrency race: more uploads than free slots fails cleanly
        // instead of indexing past the free-slot list.
        $this->expectException(GroupEventActionException::class);
        app(UpdateEvent::class)($author, $event, $this->form(), ImageEdit::of([$this->fake('c.png'), $this->fake('d.png')]));
    }

    public function test_a_remove_id_from_another_event_is_ignored(): void
    {
        $group = Group::factory()->create();
        $author = $this->joined($group);
        $event = $this->eventWith($group, $author, [$this->fake('mine.png')]);
        $other = $this->eventWith($group, $author, [$this->fake('theirs.png')]);
        $otherImage = $other->images()->first();

        $this->actingAs($author)->post(route('group.events.update', $event), $this->payloadFrom($event, [
            'remove_images' => [$otherImage->id],
        ]))->assertRedirect(route('group.events.show', $event));

        // Neither the other event's image nor this event's own image was removed.
        $this->assertNotNull(GroupEventImage::find($otherImage->id));
        $this->assertSame(1, $event->images()->count());
    }

    public function test_a_failed_added_image_rolls_back_the_removal_and_leaves_no_orphan(): void
    {
        config(['openpne.files.disk' => 'local']);
        Storage::fake('local');

        $group = Group::factory()->create();
        $author = $this->joined($group);
        $event = $this->eventWith($group, $author, [$this->fake('keep.png')]); // slot 1, bytes on the fake disk
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
            app(UpdateEvent::class)($author, $event->fresh(), $this->form(), ImageEdit::of([$this->fake('a.png'), $this->fake('b.png')], [$image1->id]));
            $this->fail('expected the failed image store to throw');
        } catch (RuntimeException) {
            // expected
        }

        // The removal rolled back: the original image and its bytes survive, no new image was added.
        $this->assertNotNull(GroupEventImage::find($image1->id));
        $this->assertSame(1, $event->fresh()->images()->count());
        $this->assertTrue(Storage::disk('local')->exists($file1->name));
        // Only the surviving image's bytes remain — the first added image was compensated.
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    public function test_a_text_only_edit_leaves_images_untouched(): void
    {
        $group = Group::factory()->create();
        $author = $this->joined($group);
        $event = $this->eventWith($group, $author, [$this->fake('a.png')]); // slot 1

        $this->actingAs($author)->post(route('group.events.update', $event), $this->payloadFrom($event, [
            'name' => 'Renamed',
        ]))->assertRedirect(route('group.events.show', $event));

        $this->assertSame('Renamed', $event->fresh()->name);
        $this->assertSame([1], $event->fresh()->images()->pluck('number')->all());
    }
}
