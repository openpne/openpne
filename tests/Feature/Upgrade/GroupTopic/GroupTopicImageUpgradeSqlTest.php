<?php

namespace Tests\Feature\Upgrade\GroupTopic;

use App\Models\GroupTopic;
use App\Models\GroupTopicComment;
use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\Steps\GroupTopicCommentImageUpgrade;
use App\Upgrade\Steps\GroupTopicImageUpgrade;
use App\Upgrade\UpgradeStep;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Upgrade\UpgradeSqlTestCase;

/**
 * The group-topic image steps: the join rows copy verbatim (post_id / file_id / number) and a
 * placeholder row with a null file_id is dropped (OpenPNE 4 requires the file).
 */
class GroupTopicImageUpgradeSqlTest extends UpgradeSqlTestCase
{
    protected function sourceTables(): array
    {
        return ['community_topic_image', 'community_topic_comment_image'];
    }

    public function test_copies_topic_images_and_drops_null_file_rows(): void
    {
        $topic = GroupTopic::factory()->create();
        $this->seedFile(1);
        $this->seedFile(2);
        $this->seedImage('community_topic_image', 1, $topic->id, 1, 1);
        $this->seedImage('community_topic_image', 2, $topic->id, 2, 2);
        $this->seedImage('community_topic_image', 3, $topic->id, null, 3); // placeholder, dropped

        $this->runUpgrade(new GroupTopicImageUpgrade);

        $this->assertDatabaseCount('group_topic_images', 2);
        $this->assertDatabaseHas('group_topic_images', ['post_id' => $topic->id, 'file_id' => 1, 'number' => 1]);
        $this->assertDatabaseHas('group_topic_images', ['post_id' => $topic->id, 'file_id' => 2, 'number' => 2]);
    }

    public function test_copies_topic_comment_images(): void
    {
        $comment = GroupTopicComment::factory()->create();
        $this->seedFile(5);
        $this->seedImage('community_topic_comment_image', 1, $comment->id, 5, 1);

        $this->runUpgrade(new GroupTopicCommentImageUpgrade);

        $this->assertDatabaseHas('group_topic_comment_images', ['post_id' => $comment->id, 'file_id' => 5, 'number' => 1]);
    }

    private function runUpgrade(UpgradeStep $step): void
    {
        DB::statement((new InsertSelectCompiler)->compile($step));
    }

    private function seedFile(int $id): void
    {
        DB::table('files')->insert([
            'id' => $id,
            'name' => "tok_{$id}",
            'type' => 'image/png',
            'byte_size' => 128,
            'created_at' => '2016-01-01 00:00:00',
            'updated_at' => '2016-01-01 00:00:00',
        ]);
    }

    private function seedImage(string $table, int $id, int $postId, ?int $fileId, int $number): void
    {
        DB::table($table)->insert([
            'id' => $id,
            'post_id' => $postId,
            'file_id' => $fileId,
            'number' => $number,
        ]);
    }
}
