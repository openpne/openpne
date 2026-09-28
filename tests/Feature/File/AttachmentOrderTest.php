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
    public function test_the_relation_orders_by_number_then_id(string $parent, string $relation, string $attachment): void
    {
        $owner = $parent::factory()->create();

        $orders = $this->orderClausesOn((new $attachment)->getTable(), fn () => $owner->{$relation}()->get());

        $this->assertSame(['order by number asc, id asc'], $orders);
    }

    /** @return array<string, array{class-string<Model>, string, class-string<Model>}> */
    public static function relations(): array
    {
        return [
            'diary' => [Diary::class, 'images', DiaryImage::class],
            'timeline post' => [TimelinePost::class, 'images', TimelinePostImage::class],
            'group topic' => [GroupTopic::class, 'images', GroupTopicImage::class],
            'group topic comment' => [GroupTopicComment::class, 'images', GroupTopicCommentImage::class],
            'group event' => [GroupEvent::class, 'images', GroupEventImage::class],
            'group event comment' => [GroupEventComment::class, 'images', GroupEventCommentImage::class],
            'group message' => [GroupMessage::class, 'images', GroupMessageImage::class],
            'direct message' => [DirectMessage::class, 'files', DirectMessageFile::class],
        ];
    }
}
