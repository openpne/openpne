<?php

namespace Tests\Feature\Upgrade\Diary;

use App\Models\DiaryComment;
use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\SourceSchema;
use App\Upgrade\Steps\DiaryCommentImageUpgrade;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Runs the compiled diary-comment-image step against the real OpenPNE 3 DDL: the join rows copy
 * verbatim (diary_comment_id / file_id, no number column). FileUpgrade preserves file.id.
 *
 * MySQL only: the set-based copy and the source DDL are MySQL features.
 */
class DiaryCommentImageUpgradeSqlTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Upgrade INSERT...SELECT runs on MySQL (source DDL + set-based copy).');
        }

        DB::statement('DROP TABLE IF EXISTS `diary_comment_image`');
        DB::statement(SourceSchema::default()->createStatement('diary_comment_image', withoutForeignKeys: true));
    }

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('DROP TABLE IF EXISTS `diary_comment_image`');
        }

        parent::tearDown();
    }

    public function test_copies_diary_comment_images_verbatim(): void
    {
        $comment = DiaryComment::factory()->create();
        $this->seedFile(1);
        $this->seedFile(2);
        $this->seedImage(1, $comment->id, 1);
        $this->seedImage(2, $comment->id, 2);

        DB::statement((new InsertSelectCompiler)->compile(new DiaryCommentImageUpgrade));

        $this->assertDatabaseCount('diary_comment_images', 2);
        $this->assertDatabaseHas('diary_comment_images', ['diary_comment_id' => $comment->id, 'file_id' => 1]);
        $this->assertDatabaseHas('diary_comment_images', ['diary_comment_id' => $comment->id, 'file_id' => 2]);
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

    private function seedImage(int $id, int $commentId, int $fileId): void
    {
        DB::table('diary_comment_image')->insert([
            'id' => $id,
            'diary_comment_id' => $commentId,
            'file_id' => $fileId,
        ]);
    }
}
