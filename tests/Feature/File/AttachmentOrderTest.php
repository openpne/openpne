<?php

namespace Tests\Feature\File;

use App\Models\Diary;
use App\Models\DiaryImage;
use App\Models\DirectMessage;
use App\Models\DirectMessageFile;
use App\Models\GroupEvent;
use App\Models\GroupEventComment;
use App\Models\GroupEventCommentImage;
use App\Models\GroupEventImage;
use App\Models\GroupMessage;
use App\Models\GroupMessageImage;
use App\Models\GroupTopic;
use App\Models\GroupTopicComment;
use App\Models\GroupTopicCommentImage;
use App\Models\GroupTopicImage;
use App\Models\TimelinePost;
use App\Models\TimelinePostImage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PinsOrderBy;
use Tests\TestCase;

class AttachmentOrderTest extends TestCase
{
    use PinsOrderBy;
    use RefreshDatabase;

    /**
     * @param  class-string<Model>  $parent
     * @param  class-string<Model>  $attachment
     */
    #[DataProvider('relations')]
    public function test_attachments_sharing_a_number_come_back_in_id_order(string $parent, string $relation, string $attachment, string $foreignKey): void
    {
        $owner = $parent::factory()->create();
        $second = $attachment::factory()->create([$foreignKey => $owner->getKey(), 'number' => 2]);
        $tied = $attachment::factory()->count(2)->create([$foreignKey => $owner->getKey(), 'number' => 1]);

        $this->assertSame(
            [...$tied->modelKeys(), $second->getKey()],
            $owner->{$relation}()->get()->modelKeys(),
        );
    }

    /**
     * @param  class-string<Model>  $parent
     * @param  class-string<Model>  $attachment
     */
    #[DataProvider('relations')]
    public function test_the_relation_orders_by_number_then_id(string $parent, string $relation, string $attachment): void
    {
        $owner = $parent::factory()->create();

        $orders = $this->orderClausesOn((new $attachment)->getTable(), fn () => $owner->{$relation}()->get());

        $this->assertSame(['order by number asc, id asc'], $orders);
    }

    /** @return array<string, array{class-string<Model>, string, class-string<Model>, string}> */
    public static function relations(): array
    {
        return [
            'diary' => [Diary::class, 'images', DiaryImage::class, 'diary_id'],
            'timeline post' => [TimelinePost::class, 'images', TimelinePostImage::class, 'timeline_post_id'],
            'group topic' => [GroupTopic::class, 'images', GroupTopicImage::class, 'post_id'],
            'group topic comment' => [GroupTopicComment::class, 'images', GroupTopicCommentImage::class, 'post_id'],
            'group event' => [GroupEvent::class, 'images', GroupEventImage::class, 'post_id'],
            'group event comment' => [GroupEventComment::class, 'images', GroupEventCommentImage::class, 'post_id'],
            'group message' => [GroupMessage::class, 'images', GroupMessageImage::class, 'group_message_id'],
            'direct message' => [DirectMessage::class, 'files', DirectMessageFile::class, 'direct_message_id'],
        ];
    }
}
