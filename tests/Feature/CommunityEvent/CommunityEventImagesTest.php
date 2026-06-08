<?php

namespace Tests\Feature\CommunityEvent;

use App\Features\Community\CommunityRole;
use App\Features\CommunityEvent\Actions\CreateEvent;
use App\Features\CommunityEvent\Actions\CreateEventComment;
use App\Features\CommunityEvent\Actions\DeleteEvent;
use App\Features\CommunityEvent\Actions\DeleteEventComment;
use App\Features\CommunityEvent\Actions\SubmitEventComment;
use App\Features\CommunityEvent\Data\CommunityEventFormData;
use App\Features\CommunityTopic\TopicReadAccess;
use App\Files\DiskFileStorage;
use App\Files\FileStorage;
use App\Models\Community;
use App\Models\CommunityEvent;
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

class CommunityEventImagesTest extends TestCase
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

    private function form(): CommunityEventFormData
    {
        return new CommunityEventFormData(
            name: 'Event',
            body: 'Body',
            open_date: now()->addWeek()->format('Y-m-d'),
            open_date_comment: '19:00-',
            area: 'Shibuya',
            application_deadline: null,
            capacity: null,
        );
    }

    /** @return array<string, mixed> */
    private function eventPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Event',
            'body' => 'Body',
            'open_date' => now()->addWeek()->format('Y-m-d'),
            'open_date_comment' => '19:00-',
            'area' => 'Shibuya',
        ], $overrides);
    }

    private function fake(string $name = 'i.png'): UploadedFile
    {
        return UploadedFile::fake()->image($name, 20, 20);
    }

    public function test_an_event_is_created_with_numbered_images_it_owns_and_shows_them(): void
    {
        $community = Community::factory()->create();
        $member = $this->joined($community);

        $this->actingAs($member)->post(route('communityEvent.store', $community), $this->eventPayload([
            'name' => 'With pics',
            'images' => [$this->fake('a.png'), $this->fake('b.png')],
        ]))->assertRedirect();

        $event = CommunityEvent::where('name', 'With pics')->firstOrFail();
        $this->assertSame([1, 2], $event->images()->pluck('number')->all());

        $file = $event->images()->with('file')->first()->file;
        // The image File is owned by the event, the source of its visibility.
        $this->assertSame('communityEvent', $file->related_entity_type);
        $this->assertSame($event->getKey(), $file->related_entity_id);

        $this->actingAs($member)->get(route('communityEvent.show', $event))
            ->assertOk()
            ->assertSee($file->thumbnailUrl(120, 120, square: true), escape: false);
    }

    public function test_a_comment_is_posted_with_images_owned_by_the_comment(): void
    {
        $community = Community::factory()->create();
        $member = $this->joined($community);
        $event = CommunityEvent::factory()->create(['community_id' => $community->getKey()]);

        // The merged endpoint: "comment only" plus an image attachment.
        $this->actingAs($member)->post(route('communityEvent.comment.store', $event), [
            'body' => 'Reply with a pic',
            'comment' => 'Add a comment only',
            'images' => [$this->fake('c.png')],
        ])->assertRedirect(route('communityEvent.show', $event));

        $comment = $event->comments()->firstOrFail();
        $file = $comment->images()->with('file')->first()->file;
        $this->assertSame('communityEventComment', $file->related_entity_type);
        $this->assertSame($comment->getKey(), $file->related_entity_id);
    }

    public function test_a_members_only_communitys_event_image_is_private_to_non_members(): void
    {
        $community = Community::factory()->create(['topic_read_access' => TopicReadAccess::MembersOnly]);
        $member = $this->joined($community);
        $event = app(CreateEvent::class)($member, $community, $this->form(), [$this->fake('x.png')]);
        $file = $event->images()->with('file')->first()->file;

        // Inherits the community's read access: a stranger is denied (404, never 403), a member may fetch.
        $this->actingAs(Member::factory()->create())->get($file->url())->assertNotFound();
        $this->actingAs($member)->get($file->url())->assertOk();
    }

    public function test_deleting_an_event_purges_its_and_its_comments_image_bytes(): void
    {
        $community = Community::factory()->create();
        $author = $this->joined($community);
        $event = app(CreateEvent::class)($author, $community, $this->form(), [$this->fake('t.png')]);
        app(CreateEventComment::class)($author, $event, 'reply', [$this->fake('c.png')]);

        $eventFile = $event->images()->with('file')->first()->file;
        $commentFile = $event->comments()->firstOrFail()->images()->with('file')->first()->file;

        app(DeleteEvent::class)($author, $event->fresh());

        // The link rows go with the cascade; the owned File bytes are purged explicitly.
        $this->assertNull(File::find($eventFile->getKey()));
        $this->assertNull(File::find($commentFile->getKey()));
        $this->assertSame(0, DB::table('file_bin')->whereIn('file_id', [$eventFile->getKey(), $commentFile->getKey()])->count());
        $this->assertDatabaseCount('community_event_images', 0);
        $this->assertDatabaseCount('community_event_comment_images', 0);
    }

    public function test_deleting_a_comment_purges_its_image_bytes(): void
    {
        $community = Community::factory()->create();
        $author = $this->joined($community);
        $event = CommunityEvent::factory()->create(['community_id' => $community->getKey(), 'member_id' => $author->getKey()]);
        $comment = app(CreateEventComment::class)($author, $event, 'reply', [$this->fake('c.png')]);
        $file = $comment->images()->with('file')->first()->file;

        app(DeleteEventComment::class)($author, $comment->fresh());

        $this->assertNull(File::find($file->getKey()));
        $this->assertSame(0, DB::table('file_bin')->where('file_id', $file->getKey())->count());
    }

    public function test_more_than_three_images_are_rejected(): void
    {
        $community = Community::factory()->create();
        $member = $this->joined($community);

        $this->actingAs($member)->post(route('communityEvent.store', $community), $this->eventPayload([
            'images' => [$this->fake('1.png'), $this->fake('2.png'), $this->fake('3.png'), $this->fake('4.png')],
        ]))->assertSessionHasErrors('images');

        $this->assertDatabaseCount('community_events', 0);
    }

    public function test_a_non_image_attachment_is_rejected(): void
    {
        $community = Community::factory()->create();
        $member = $this->joined($community);

        $this->actingAs($member)->post(route('communityEvent.store', $community), $this->eventPayload([
            'images' => [UploadedFile::fake()->create('notes.txt', 10, 'text/plain')],
        ]))->assertSessionHasErrors('images.0');

        $this->assertDatabaseCount('community_events', 0);
    }

    public function test_a_failed_later_image_compensates_the_earlier_images_bytes(): void
    {
        // Drive a disk backend so writeStream lands real bytes, then fail the second write. The first
        // image's bytes must be compensated off the disk when the outer transaction rolls back — a
        // disk write is not transactional, so without compensation it would orphan.
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
            app(CreateEvent::class)($member, $community, $this->form(), [$this->fake('1.png'), $this->fake('2.png')]);
            $this->fail('expected the failed image store to throw');
        } catch (RuntimeException) {
            // expected
        }

        // The transaction rolled back wholesale: no event, no File rows, no link rows.
        $this->assertDatabaseCount('community_events', 0);
        $this->assertDatabaseCount('files', 0);
        $this->assertDatabaseCount('community_event_images', 0);
        // And the first image's bytes were compensated — no orphan left on disk.
        $this->assertEmpty(Storage::disk('local')->allFiles());
    }

    public function test_a_failed_image_in_a_merged_participate_rolls_back_the_join_too(): void
    {
        // The merged endpoint runs the roster toggle, the comment and its images in ONE compensating
        // transaction. A failed image write must undo the join and the comment as well, and leave no
        // orphan bytes — a separate outer transaction around two self-transacting actions would not.
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
        $event = CommunityEvent::factory()->create(['community_id' => $community->getKey()]);

        try {
            app(SubmitEventComment::class)($member, $event, 'Count me in!', [$this->fake('1.png'), $this->fake('2.png')], true);
            $this->fail('expected the failed image store to throw');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame(0, $event->fresh()->participantCount()); // the join rolled back
        $this->assertDatabaseCount('community_event_comments', 0); // the comment rolled back
        $this->assertDatabaseCount('community_event_comment_images', 0);
        $this->assertDatabaseCount('files', 0);
        $this->assertEmpty(Storage::disk('local')->allFiles()); // no orphan bytes
    }
}
