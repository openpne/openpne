<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Features\Community\JoinPolicy;
use App\Features\CommunityTopic\TopicPostAuthority;
use App\Features\CommunityTopic\TopicReadAccess;
use App\Filament\Resources\Communities\Pages\EditCommunity;
use App\Filament\Resources\Communities\Pages\ListCommunities;
use App\Models\AdminUser;
use App\Models\Community;
use App\Models\CommunityCategory;
use App\Models\CommunityEvent;
use App\Models\CommunityEventComment;
use App\Models\CommunityEventCommentImage;
use App\Models\CommunityEventImage;
use App\Models\CommunityTopic;
use App\Models\CommunityTopicComment;
use App\Models\CommunityTopicCommentImage;
use App\Models\CommunityTopicImage;
use App\Models\File;
use App\Models\Member;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CommunityResourceTest extends TestCase
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
        $communities = Community::factory()->count(2)->create();

        Livewire::test(ListCommunities::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords($communities);
    }

    public function test_search_by_name(): void
    {
        $match = Community::factory()->create(['name' => 'Findme Community']);
        $other = Community::factory()->create(['name' => 'Unrelated']);

        Livewire::test(ListCommunities::class)
            ->searchTable('Findme')
            ->assertCanSeeTableRecords([$match])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_edit_persists_typed_columns(): void
    {
        $category = CommunityCategory::factory()->create();
        $community = Community::factory()->create(['register_policy' => JoinPolicy::Open]);

        Livewire::test(EditCommunity::class, ['record' => $community->getKey()])
            ->fillForm([
                'name' => 'Renamed Community',
                'description' => 'Edited by an admin.',
                'community_category_id' => $category->getKey(),
                'register_policy' => JoinPolicy::Approval->value,
                'topic_read_access' => TopicReadAccess::MembersOnly->value,
                'topic_post_authority' => TopicPostAuthority::AdminsOnly->value,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $community->refresh();
        $this->assertSame('Renamed Community', $community->name);
        $this->assertSame($category->getKey(), $community->community_category_id);
        $this->assertSame(JoinPolicy::Approval, $community->register_policy);
        $this->assertSame(TopicReadAccess::MembersOnly, $community->topic_read_access);
        $this->assertSame(TopicPostAuthority::AdminsOnly, $community->topic_post_authority);
    }

    public function test_delete_purges_community_and_all_nested_image_files(): void
    {
        $community = Community::factory()->create();

        $topic = CommunityTopic::factory()->create(['community_id' => $community->getKey()]);
        $topicImage = CommunityTopicImage::factory()->create(['post_id' => $topic->getKey()]);
        $topicComment = CommunityTopicComment::factory()->create(['community_topic_id' => $topic->getKey()]);
        $topicCommentImage = CommunityTopicCommentImage::factory()->create(['post_id' => $topicComment->getKey()]);

        $event = CommunityEvent::factory()->create(['community_id' => $community->getKey()]);
        $eventImage = CommunityEventImage::factory()->create(['post_id' => $event->getKey()]);
        $eventComment = CommunityEventComment::factory()->create(['community_event_id' => $event->getKey()]);
        $eventCommentImage = CommunityEventCommentImage::factory()->create(['post_id' => $eventComment->getKey()]);

        $files = [
            File::findOrFail($topicImage->file_id),
            File::findOrFail($topicCommentImage->file_id),
            File::findOrFail($eventImage->file_id),
            File::findOrFail($eventCommentImage->file_id),
        ];

        Livewire::test(ListCommunities::class)
            ->callAction(TestAction::make('delete')->table($community))
            ->assertNotified(__('filament-actions::delete.single.notifications.deleted.title'));

        $this->assertModelMissing($community);
        $this->assertModelMissing($topic);
        $this->assertModelMissing($event);

        // The fix: nested topic/event/comment image File bytes are purged, not orphaned.
        foreach ($files as $file) {
            $this->assertModelMissing($file);
        }
    }

    public function test_toggle_default_flips_the_flag(): void
    {
        $community = Community::factory()->create(['is_default' => false]);

        Livewire::test(ListCommunities::class)
            ->callAction(TestAction::make('toggleDefault')->table($community));

        $this->assertTrue($community->refresh()->is_default);

        Livewire::test(ListCommunities::class)
            ->callAction(TestAction::make('toggleDefault')->table($community));

        $this->assertFalse($community->refresh()->is_default);
    }

    public function test_add_all_members_action_joins_outsiders(): void
    {
        $community = Community::factory()->create();
        $a = Member::factory()->create();
        $b = Member::factory()->create();

        Livewire::test(ListCommunities::class)
            ->callAction(TestAction::make('addAllMembers')->table($community))
            ->assertNotified(__('Members added'));

        $this->assertDatabaseHas('community_members', ['community_id' => $community->getKey(), 'member_id' => $a->getKey()]);
        $this->assertDatabaseHas('community_members', ['community_id' => $community->getKey(), 'member_id' => $b->getKey()]);
    }
}
