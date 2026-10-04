<?php

namespace Tests\Feature\Upgrade\GroupEvent;

use App\Models\GroupEvent;
use App\Models\GroupEventComment;
use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\Steps\GroupEventCommentImageUpgrade;
use App\Upgrade\Steps\GroupEventImageUpgrade;
use App\Upgrade\UpgradeStep;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Upgrade\UpgradeSqlTestCase;

/**
 * The group-event image steps: the join rows copy verbatim (post_id / file_id / number) and a
 * placeholder row with a null file_id is dropped (OpenPNE 4 requires the file).
 */
class GroupEventImageUpgradeSqlTest extends UpgradeSqlTestCase
{
    protected function sourceTables(): array
    {
        return ['community_event_image', 'community_event_comment_image'];
    }

    public function test_copies_event_images_and_drops_null_file_rows(): void
    {
        $event = GroupEvent::factory()->create();
        $this->seedFile(1);
        $this->seedFile(2);
        $this->seedImage('community_event_image', 1, $event->id, 1, 1);
        $this->seedImage('community_event_image', 2, $event->id, 2, 2);
        $this->seedImage('community_event_image', 3, $event->id, null, 3); // placeholder, dropped

        $this->runUpgrade(new GroupEventImageUpgrade);

        $this->assertDatabaseCount('group_event_images', 2);
        $this->assertDatabaseHas('group_event_images', ['post_id' => $event->id, 'file_id' => 1, 'number' => 1]);
        $this->assertDatabaseHas('group_event_images', ['post_id' => $event->id, 'file_id' => 2, 'number' => 2]);
    }

    public function test_copies_event_comment_images(): void
    {
        $comment = GroupEventComment::factory()->create();
        $this->seedFile(5);
        $this->seedImage('community_event_comment_image', 1, $comment->id, 5, 1);

        $this->runUpgrade(new GroupEventCommentImageUpgrade);

        $this->assertDatabaseHas('group_event_comment_images', ['post_id' => $comment->id, 'file_id' => 5, 'number' => 1]);
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
