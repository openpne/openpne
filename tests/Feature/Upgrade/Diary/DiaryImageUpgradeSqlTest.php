<?php

namespace Tests\Feature\Upgrade\Diary;

use App\Models\Diary;
use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\SourceSchema;
use App\Upgrade\Steps\DiaryImageUpgrade;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Runs the compiled diary-image step against the real OpenPNE 3 DDL: the join rows copy verbatim
 * (diary_id / file_id / number, FileUpgrade preserves file.id).
 *
 * MySQL only: the set-based copy and the source DDL are MySQL features.
 */
class DiaryImageUpgradeSqlTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Upgrade INSERT...SELECT runs on MySQL (source DDL + set-based copy).');
        }

        DB::statement('DROP TABLE IF EXISTS `diary_image`');
        DB::statement(SourceSchema::default()->createStatement('diary_image', withoutForeignKeys: true));
    }

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('DROP TABLE IF EXISTS `diary_image`');
        }

        parent::tearDown();
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
