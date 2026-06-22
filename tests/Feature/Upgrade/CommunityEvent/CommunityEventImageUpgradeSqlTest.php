<?php

namespace Tests\Feature\Upgrade\CommunityEvent;

use App\Models\CommunityEvent;
use App\Models\CommunityEventComment;
use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\SourceSchema;
use App\Upgrade\Steps\CommunityEventCommentImageUpgrade;
use App\Upgrade\Steps\CommunityEventImageUpgrade;
use App\Upgrade\UpgradeStep;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Runs the compiled community-event image steps against the real OpenPNE 3 DDL: the join rows copy
 * verbatim (post_id / file_id / number) and a placeholder row with a null file_id is dropped (OpenPNE
 * 4 requires the file).
 *
 * MySQL only: the set-based copy and the source DDL are MySQL features.
 */
class CommunityEventImageUpgradeSqlTest extends TestCase
{
    use DatabaseMigrations;

    private array $sourceTables = ['community_event_image', 'community_event_comment_image'];

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

    public function test_copies_event_images_and_drops_null_file_rows(): void
    {
        $event = CommunityEvent::factory()->create();
        $this->seedFile(1);
        $this->seedFile(2);
        $this->seedImage('community_event_image', 1, $event->id, 1, 1);
        $this->seedImage('community_event_image', 2, $event->id, 2, 2);
        $this->seedImage('community_event_image', 3, $event->id, null, 3); // placeholder, dropped

        $this->runUpgrade(new CommunityEventImageUpgrade);

        $this->assertDatabaseCount('community_event_images', 2);
        $this->assertDatabaseHas('community_event_images', ['post_id' => $event->id, 'file_id' => 1, 'number' => 1]);
        $this->assertDatabaseHas('community_event_images', ['post_id' => $event->id, 'file_id' => 2, 'number' => 2]);
    }

    public function test_copies_event_comment_images(): void
    {
        $comment = CommunityEventComment::factory()->create();
        $this->seedFile(5);
        $this->seedImage('community_event_comment_image', 1, $comment->id, 5, 1);

        $this->runUpgrade(new CommunityEventCommentImageUpgrade);

        $this->assertDatabaseHas('community_event_comment_images', ['post_id' => $comment->id, 'file_id' => 5, 'number' => 1]);
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
