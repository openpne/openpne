<?php

namespace Tests\Feature\Upgrade\Diary;

use App\Models\DiaryComment;
use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\Steps\DiaryCommentImageUpgrade;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Upgrade\UpgradeSqlTestCase;

class DiaryCommentImageUpgradeSqlTest extends UpgradeSqlTestCase
{
    protected function sourceTables(): array
    {
        return ['diary_comment_image'];
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
