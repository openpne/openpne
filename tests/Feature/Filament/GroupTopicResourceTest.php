<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\GroupTopics\GroupTopicResource;
use App\Filament\Resources\GroupTopics\Pages\ListGroupTopics;
use App\Filament\Resources\GroupTopics\Pages\ViewGroupTopic;
use App\Filament\Resources\GroupTopics\RelationManagers\TopicCommentsRelationManager;
use App\Models\AdminUser;
use App\Models\File;
use App\Models\GroupTopic;
use App\Models\GroupTopicComment;
use App\Models\GroupTopicCommentImage;
use App\Models\GroupTopicImage;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class GroupTopicResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->actingAs(AdminUser::factory()->create(), 'admin');
    }

    public function test_list_page_renders_topics(): void
    {
        $topics = GroupTopic::factory()->count(2)->create();

        Livewire::test(ListGroupTopics::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords($topics);
    }

    public function test_search_by_name(): void
    {
        $match = GroupTopic::factory()->create(['name' => 'Findme Topic']);
        $other = GroupTopic::factory()->create(['name' => 'Unrelated']);

        Livewire::test(ListGroupTopics::class)
            ->searchTable('Findme')
            ->assertCanSeeTableRecords([$match])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_view_page_loads_with_comments(): void
    {
        $topic = GroupTopic::factory()->create();
        $comment = GroupTopicComment::factory()->create(['group_topic_id' => $topic->getKey()]);

        Livewire::test(TopicCommentsRelationManager::class, [
            'ownerRecord' => $topic,
            'pageClass' => ViewGroupTopic::class,
        ])
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$comment]);

        $this->get(GroupTopicResource::getUrl('view', ['record' => $topic]))->assertOk();
    }

    public function test_admin_delete_removes_topic_and_purges_image_files(): void
    {
        $topic = GroupTopic::factory()->create();
        $topicImage = GroupTopicImage::factory()->create(['post_id' => $topic->getKey()]);
        $comment = GroupTopicComment::factory()->create(['group_topic_id' => $topic->getKey()]);
        $commentImage = GroupTopicCommentImage::factory()->create(['post_id' => $comment->getKey()]);
        $topicFile = File::findOrFail($topicImage->file_id);
        $commentFile = File::findOrFail($commentImage->file_id);

        Livewire::test(ListGroupTopics::class)
            ->callAction(TestAction::make('delete')->table($topic))
            ->assertNotified(__('filament-actions::delete.single.notifications.deleted.title'));

        $this->assertModelMissing($topic);
        $this->assertModelMissing($comment);
        $this->assertModelMissing($topicFile);
        $this->assertModelMissing($commentFile);
    }

    public function test_admin_delete_comment_via_relation_manager(): void
    {
        $topic = GroupTopic::factory()->create();
        $comment = GroupTopicComment::factory()->create(['group_topic_id' => $topic->getKey()]);
        $commentImage = GroupTopicCommentImage::factory()->create(['post_id' => $comment->getKey()]);
        $commentFile = File::findOrFail($commentImage->file_id);

        Livewire::test(TopicCommentsRelationManager::class, [
            'ownerRecord' => $topic,
            'pageClass' => ViewGroupTopic::class,
        ])
            ->callAction(TestAction::make('delete')->table($comment));

        $this->assertModelMissing($comment);
        $this->assertModelMissing($commentFile);
    }
}
