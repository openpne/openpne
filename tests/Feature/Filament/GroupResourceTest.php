<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Features\Group\JoinPolicy;
use App\Features\GroupTopic\TopicPostAuthority;
use App\Features\GroupTopic\TopicReadAccess;
use App\Filament\Resources\Groups\Pages\EditGroup;
use App\Filament\Resources\Groups\Pages\ListGroups;
use App\Models\AdminUser;
use App\Models\File;
use App\Models\Group;
use App\Models\GroupCategory;
use App\Models\GroupEvent;
use App\Models\GroupEventComment;
use App\Models\GroupEventCommentImage;
use App\Models\GroupEventImage;
use App\Models\GroupTopic;
use App\Models\GroupTopicComment;
use App\Models\GroupTopicCommentImage;
use App\Models\GroupTopicImage;
use App\Models\Member;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class GroupResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->actingAs(AdminUser::factory()->create(), 'admin');
    }

    public function test_list_page_renders_communities(): void
    {
        $groups = Group::factory()->count(2)->create();

        Livewire::test(ListGroups::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords($groups);
    }

    public function test_search_by_name(): void
    {
        $match = Group::factory()->create(['name' => 'Findme Group']);
        $other = Group::factory()->create(['name' => 'Unrelated']);

        Livewire::test(ListGroups::class)
            ->searchTable('Findme')
            ->assertCanSeeTableRecords([$match])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_edit_persists_typed_columns(): void
    {
        $category = GroupCategory::factory()->create();
        $group = Group::factory()->create(['register_policy' => JoinPolicy::Open]);

        Livewire::test(EditGroup::class, ['record' => $group->getKey()])
            ->fillForm([
                'name' => 'Renamed Group',
                'description' => 'Edited by an admin.',
                'group_category_id' => $category->getKey(),
                'register_policy' => JoinPolicy::Approval->value,
                'topic_read_access' => TopicReadAccess::MembersOnly->value,
                'topic_post_authority' => TopicPostAuthority::AdminsOnly->value,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $group->refresh();
        $this->assertSame('Renamed Group', $group->name);
        $this->assertSame($category->getKey(), $group->group_category_id);
        $this->assertSame(JoinPolicy::Approval, $group->register_policy);
        $this->assertSame(TopicReadAccess::MembersOnly, $group->topic_read_access);
        $this->assertSame(TopicPostAuthority::AdminsOnly, $group->topic_post_authority);
    }

    public function test_delete_purges_community_and_all_nested_image_files(): void
    {
        $group = Group::factory()->create();

        $topic = GroupTopic::factory()->create(['group_id' => $group->getKey()]);
        $topicImage = GroupTopicImage::factory()->create(['post_id' => $topic->getKey()]);
        $topicComment = GroupTopicComment::factory()->create(['group_topic_id' => $topic->getKey()]);
        $topicCommentImage = GroupTopicCommentImage::factory()->create(['post_id' => $topicComment->getKey()]);

        $event = GroupEvent::factory()->create(['group_id' => $group->getKey()]);
        $eventImage = GroupEventImage::factory()->create(['post_id' => $event->getKey()]);
        $eventComment = GroupEventComment::factory()->create(['group_event_id' => $event->getKey()]);
        $eventCommentImage = GroupEventCommentImage::factory()->create(['post_id' => $eventComment->getKey()]);

        $files = [
            File::findOrFail($topicImage->file_id),
            File::findOrFail($topicCommentImage->file_id),
            File::findOrFail($eventImage->file_id),
            File::findOrFail($eventCommentImage->file_id),
        ];

        Livewire::test(ListGroups::class)
            ->callAction(TestAction::make('delete')->table($group))
            ->assertNotified(__('filament-actions::delete.single.notifications.deleted.title'));

        $this->assertModelMissing($group);
        $this->assertModelMissing($topic);
        $this->assertModelMissing($event);

        // The fix: nested topic/event/comment image File bytes are purged, not orphaned.
        foreach ($files as $file) {
            $this->assertModelMissing($file);
        }
    }

    public function test_toggle_default_flips_the_flag(): void
    {
        $group = Group::factory()->create(['is_default' => false]);

        Livewire::test(ListGroups::class)
            ->callAction(TestAction::make('toggleDefault')->table($group));

        $this->assertTrue($group->refresh()->is_default);

        Livewire::test(ListGroups::class)
            ->callAction(TestAction::make('toggleDefault')->table($group));

        $this->assertFalse($group->refresh()->is_default);
    }

    public function test_add_all_members_action_joins_outsiders(): void
    {
        $group = Group::factory()->create();
        $a = Member::factory()->create();
        $b = Member::factory()->create();

        Livewire::test(ListGroups::class)
            ->callAction(TestAction::make('addAllMembers')->table($group))
            ->assertNotified(__('Members added'));

        $this->assertDatabaseHas('group_members', ['group_id' => $group->getKey(), 'member_id' => $a->getKey()]);
        $this->assertDatabaseHas('group_members', ['group_id' => $group->getKey(), 'member_id' => $b->getKey()]);
    }
}
