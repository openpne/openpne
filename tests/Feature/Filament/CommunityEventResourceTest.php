<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\CommunityEvents\CommunityEventResource;
use App\Filament\Resources\CommunityEvents\Pages\ListCommunityEvents;
use App\Filament\Resources\CommunityEvents\Pages\ViewCommunityEvent;
use App\Filament\Resources\CommunityEvents\RelationManagers\EventCommentsRelationManager;
use App\Filament\Resources\CommunityEvents\RelationManagers\EventMembersRelationManager;
use App\Models\AdminUser;
use App\Models\CommunityEvent;
use App\Models\CommunityEventComment;
use App\Models\CommunityEventCommentImage;
use App\Models\CommunityEventImage;
use App\Models\CommunityEventMember;
use App\Models\File;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CommunityEventResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->actingAs(AdminUser::factory()->create(), 'admin');
    }

    public function test_list_page_renders_events(): void
    {
        $events = CommunityEvent::factory()->count(2)->create();

        Livewire::test(ListCommunityEvents::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords($events);
    }

    public function test_search_by_name(): void
    {
        $match = CommunityEvent::factory()->create(['name' => 'Findme Event']);
        $other = CommunityEvent::factory()->create(['name' => 'Unrelated']);

        Livewire::test(ListCommunityEvents::class)
            ->searchTable('Findme')
            ->assertCanSeeTableRecords([$match])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_view_page_loads_with_comments_and_participants(): void
    {
        $event = CommunityEvent::factory()->create();
        $comment = CommunityEventComment::factory()->create(['community_event_id' => $event->getKey()]);
        $rsvp = CommunityEventMember::factory()->create(['community_event_id' => $event->getKey()]);

        Livewire::test(EventCommentsRelationManager::class, [
            'ownerRecord' => $event,
            'pageClass' => ViewCommunityEvent::class,
        ])->assertSuccessful()->assertCanSeeTableRecords([$comment]);

        Livewire::test(EventMembersRelationManager::class, [
            'ownerRecord' => $event,
            'pageClass' => ViewCommunityEvent::class,
        ])->assertSuccessful()->assertCanSeeTableRecords([$rsvp]);

        $this->get(CommunityEventResource::getUrl('view', ['record' => $event]))->assertOk();
    }

    public function test_admin_delete_removes_event_and_purges_image_files(): void
    {
        $event = CommunityEvent::factory()->create();
        $eventImage = CommunityEventImage::factory()->create(['post_id' => $event->getKey()]);
        $comment = CommunityEventComment::factory()->create(['community_event_id' => $event->getKey()]);
        $commentImage = CommunityEventCommentImage::factory()->create(['post_id' => $comment->getKey()]);
        $eventFile = File::findOrFail($eventImage->file_id);
        $commentFile = File::findOrFail($commentImage->file_id);

        Livewire::test(ListCommunityEvents::class)
            ->callAction(TestAction::make('delete')->table($event))
            ->assertNotified(__('filament-actions::delete.single.notifications.deleted.title'));

        $this->assertModelMissing($event);
        $this->assertModelMissing($comment);
        $this->assertModelMissing($eventFile);
        $this->assertModelMissing($commentFile);
    }

    public function test_admin_delete_comment_via_relation_manager(): void
    {
        $event = CommunityEvent::factory()->create();
        $comment = CommunityEventComment::factory()->create(['community_event_id' => $event->getKey()]);
        $commentImage = CommunityEventCommentImage::factory()->create(['post_id' => $comment->getKey()]);
        $commentFile = File::findOrFail($commentImage->file_id);

        Livewire::test(EventCommentsRelationManager::class, [
            'ownerRecord' => $event,
            'pageClass' => ViewCommunityEvent::class,
        ])->callAction(TestAction::make('delete')->table($comment));

        $this->assertModelMissing($comment);
        $this->assertModelMissing($commentFile);
    }

    public function test_admin_removes_participant_via_relation_manager(): void
    {
        $event = CommunityEvent::factory()->create();
        $rsvp = CommunityEventMember::factory()->create(['community_event_id' => $event->getKey()]);

        Livewire::test(EventMembersRelationManager::class, [
            'ownerRecord' => $event,
            'pageClass' => ViewCommunityEvent::class,
        ])->callAction(TestAction::make('delete')->table($rsvp));

        $this->assertModelMissing($rsvp);
    }
}
