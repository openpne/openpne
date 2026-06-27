<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\CommunityTopics\CommunityTopicResource;
use App\Filament\Resources\CommunityTopics\Pages\ListCommunityTopics;
use App\Filament\Resources\CommunityTopics\Pages\ViewCommunityTopic;
use App\Filament\Resources\CommunityTopics\RelationManagers\TopicCommentsRelationManager;
use App\Models\AdminUser;
use App\Models\CommunityTopic;
use App\Models\CommunityTopicComment;
use App\Models\CommunityTopicCommentImage;
use App\Models\CommunityTopicImage;
use App\Models\File;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CommunityTopicResourceTest extends TestCase
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
        $topics = CommunityTopic::factory()->count(2)->create();

        Livewire::test(ListCommunityTopics::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords($topics);
    }

    public function test_search_by_name(): void
    {
        $match = CommunityTopic::factory()->create(['name' => 'Findme Topic']);
        $other = CommunityTopic::factory()->create(['name' => 'Unrelated']);

        Livewire::test(ListCommunityTopics::class)
            ->searchTable('Findme')
            ->assertCanSeeTableRecords([$match])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_view_page_loads_with_comments(): void
    {
        $topic = CommunityTopic::factory()->create();
        $comment = CommunityTopicComment::factory()->create(['community_topic_id' => $topic->getKey()]);

        Livewire::test(TopicCommentsRelationManager::class, [
            'ownerRecord' => $topic,
            'pageClass' => ViewCommunityTopic::class,
        ])
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$comment]);

        $this->get(CommunityTopicResource::getUrl('view', ['record' => $topic]))->assertOk();
    }

    public function test_admin_delete_removes_topic_and_purges_image_files(): void
    {
        $topic = CommunityTopic::factory()->create();
        $topicImage = CommunityTopicImage::factory()->create(['post_id' => $topic->getKey()]);
        $comment = CommunityTopicComment::factory()->create(['community_topic_id' => $topic->getKey()]);
        $commentImage = CommunityTopicCommentImage::factory()->create(['post_id' => $comment->getKey()]);
        $topicFile = File::findOrFail($topicImage->file_id);
        $commentFile = File::findOrFail($commentImage->file_id);

        Livewire::test(ListCommunityTopics::class)
            ->callAction(TestAction::make('delete')->table($topic))
            ->assertNotified(__('filament-actions::delete.single.notifications.deleted.title'));

        $this->assertModelMissing($topic);
        $this->assertModelMissing($comment);
        $this->assertModelMissing($topicFile);
        $this->assertModelMissing($commentFile);
    }

    public function test_admin_delete_comment_via_relation_manager(): void
    {
        $topic = CommunityTopic::factory()->create();
        $comment = CommunityTopicComment::factory()->create(['community_topic_id' => $topic->getKey()]);
        $commentImage = CommunityTopicCommentImage::factory()->create(['post_id' => $comment->getKey()]);
        $commentFile = File::findOrFail($commentImage->file_id);

        Livewire::test(TopicCommentsRelationManager::class, [
            'ownerRecord' => $topic,
            'pageClass' => ViewCommunityTopic::class,
        ])
            ->callAction(TestAction::make('delete')->table($comment));

        $this->assertModelMissing($comment);
        $this->assertModelMissing($commentFile);
    }
}
