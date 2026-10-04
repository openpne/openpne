<?php

namespace Tests\Feature\Upgrade\Diary;

use App\Models\Diary;
use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\Steps\DiaryImageUpgrade;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Upgrade\UpgradeSqlTestCase;

/** The join rows copy verbatim (diary_id / file_id / number, FileUpgrade preserves file.id). */
class DiaryImageUpgradeSqlTest extends UpgradeSqlTestCase
{
    protected function sourceTables(): array
    {
        return ['diary_image'];
    }

    public function test_copies_diary_images_verbatim(): void
    {
        $diary = Diary::factory()->create();
        $this->seedFile(1);
        $this->seedFile(2);
        $this->seedImage(1, $diary->id, 1, 1);
        $this->seedImage(2, $diary->id, 2, 2);

        DB::statement((new InsertSelectCompiler)->compile(new DiaryImageUpgrade));

        $this->assertDatabaseCount('diary_images', 2);
        $this->assertDatabaseHas('diary_images', ['diary_id' => $diary->id, 'file_id' => 1, 'number' => 1]);
        $this->assertDatabaseHas('diary_images', ['diary_id' => $diary->id, 'file_id' => 2, 'number' => 2]);
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

    private function seedImage(int $id, int $diaryId, int $fileId, int $number): void
    {
        DB::table('diary_image')->insert([
            'id' => $id,
            'diary_id' => $diaryId,
            'file_id' => $fileId,
            'number' => $number,
        ]);
    }
}
