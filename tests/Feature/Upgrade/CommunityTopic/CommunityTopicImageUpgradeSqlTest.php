<?php

namespace Tests\Feature\Upgrade\CommunityTopic;

use App\Models\CommunityTopic;
use App\Models\CommunityTopicComment;
use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\SourceSchema;
use App\Upgrade\Steps\CommunityTopicCommentImageUpgrade;
use App\Upgrade\Steps\CommunityTopicImageUpgrade;
use App\Upgrade\UpgradeStep;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Runs the compiled community-topic image steps against the real OpenPNE 3 DDL: the join rows copy
 * verbatim (post_id / file_id / number) and a placeholder row with a null file_id is dropped (OpenPNE
 * 4 requires the file).
 *
 * MySQL only: the set-based copy and the source DDL are MySQL features.
 */
class CommunityTopicImageUpgradeSqlTest extends TestCase
{
    use DatabaseMigrations;

    private array $sourceTables = ['community_topic_image', 'community_topic_comment_image'];

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Upgrade INSERT...SELECT runs on MySQL (source DDL + set-based copy).');
        }

        foreach ($this->sourceTables as $table) {
            DB::statement("DROP TABLE IF EXISTS `{$table}`");
            DB::statement(SourceSchema::default()->createStatement($table, withoutForeignKeys: true));
        }
    }

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            foreach (array_reverse($this->sourceTables) as $table) {
                DB::statement("DROP TABLE IF EXISTS `{$table}`");
            }
        }

        parent::tearDown();
    }

    public function test_copies_topic_images_and_drops_null_file_rows(): void
    {
        $topic = CommunityTopic::factory()->create();
        $this->seedFile(1);
        $this->seedFile(2);
        $this->seedImage('community_topic_image', 1, $topic->id, 1, 1);
        $this->seedImage('community_topic_image', 2, $topic->id, 2, 2);
        $this->seedImage('community_topic_image', 3, $topic->id, null, 3); // placeholder, dropped

        $this->runUpgrade(new CommunityTopicImageUpgrade);

        $this->assertDatabaseCount('community_topic_images', 2);
        $this->assertDatabaseHas('community_topic_images', ['post_id' => $topic->id, 'file_id' => 1, 'number' => 1]);
        $this->assertDatabaseHas('community_topic_images', ['post_id' => $topic->id, 'file_id' => 2, 'number' => 2]);
    }

    public function test_copies_topic_comment_images(): void
    {
        $comment = CommunityTopicComment::factory()->create();
        $this->seedFile(5);
        $this->seedImage('community_topic_comment_image', 1, $comment->id, 5, 1);

        $this->runUpgrade(new CommunityTopicCommentImageUpgrade);

        $this->assertDatabaseHas('community_topic_comment_images', ['post_id' => $comment->id, 'file_id' => 5, 'number' => 1]);
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
