<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\GroupEvents\GroupEventResource;
use App\Filament\Resources\GroupEvents\Pages\ListGroupEvents;
use App\Filament\Resources\GroupEvents\Pages\ViewGroupEvent;
use App\Filament\Resources\GroupEvents\RelationManagers\EventCommentsRelationManager;
use App\Filament\Resources\GroupEvents\RelationManagers\EventMembersRelationManager;
use App\Models\AdminUser;
use App\Models\File;
use App\Models\GroupEvent;
use App\Models\GroupEventComment;
use App\Models\GroupEventCommentImage;
use App\Models\GroupEventImage;
use App\Models\GroupEventMember;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class GroupEventResourceTest extends TestCase
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
        $events = GroupEvent::factory()->count(2)->create();

        Livewire::test(ListGroupEvents::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords($events);
    }

    public function test_search_by_name(): void
    {
        $match = GroupEvent::factory()->create(['name' => 'Findme Event']);
        $other = GroupEvent::factory()->create(['name' => 'Unrelated']);

        Livewire::test(ListGroupEvents::class)
            ->searchTable('Findme')
            ->assertCanSeeTableRecords([$match])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_view_page_loads_with_comments_and_participants(): void
    {
        $event = GroupEvent::factory()->create();
        $comment = GroupEventComment::factory()->create(['group_event_id' => $event->getKey()]);
        $rsvp = GroupEventMember::factory()->create(['group_event_id' => $event->getKey()]);

        Livewire::test(EventCommentsRelationManager::class, [
            'ownerRecord' => $event,
            'pageClass' => ViewGroupEvent::class,
        ])->assertSuccessful()->assertCanSeeTableRecords([$comment]);

        Livewire::test(EventMembersRelationManager::class, [
            'ownerRecord' => $event,
            'pageClass' => ViewGroupEvent::class,
        ])->assertSuccessful()->assertCanSeeTableRecords([$rsvp]);

        $this->get(GroupEventResource::getUrl('view', ['record' => $event]))->assertOk();
    }

    public function test_admin_delete_removes_event_and_purges_image_files(): void
    {
        $event = GroupEvent::factory()->create();
        $eventImage = GroupEventImage::factory()->create(['post_id' => $event->getKey()]);
        $comment = GroupEventComment::factory()->create(['group_event_id' => $event->getKey()]);
        $commentImage = GroupEventCommentImage::factory()->create(['post_id' => $comment->getKey()]);
        $eventFile = File::findOrFail($eventImage->file_id);
        $commentFile = File::findOrFail($commentImage->file_id);

        Livewire::test(ListGroupEvents::class)
            ->callAction(TestAction::make('delete')->table($event))
            ->assertNotified(__('filament-actions::delete.single.notifications.deleted.title'));

        $this->assertModelMissing($event);
        $this->assertModelMissing($comment);
        $this->assertModelMissing($eventFile);
        $this->assertModelMissing($commentFile);
    }

    public function test_admin_delete_comment_via_relation_manager(): void
    {
        $event = GroupEvent::factory()->create();
        $comment = GroupEventComment::factory()->create(['group_event_id' => $event->getKey()]);
        $commentImage = GroupEventCommentImage::factory()->create(['post_id' => $comment->getKey()]);
        $commentFile = File::findOrFail($commentImage->file_id);

        Livewire::test(EventCommentsRelationManager::class, [
            'ownerRecord' => $event,
            'pageClass' => ViewGroupEvent::class,
        ])->callAction(TestAction::make('delete')->table($comment));

        $this->assertModelMissing($comment);
        $this->assertModelMissing($commentFile);
    }

    public function test_admin_removes_participant_via_relation_manager(): void
    {
        $event = GroupEvent::factory()->create();
        $rsvp = GroupEventMember::factory()->create(['group_event_id' => $event->getKey()]);

        Livewire::test(EventMembersRelationManager::class, [
            'ownerRecord' => $event,
            'pageClass' => ViewGroupEvent::class,
        ])->callAction(TestAction::make('delete')->table($rsvp));

        $this->assertModelMissing($rsvp);
    }
}
