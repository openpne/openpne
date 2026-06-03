<?php

namespace Tests\Feature\Upgrade\Diary;

use App\Models\Diary;
use App\Models\DiaryComment;
use App\Models\Member;
use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\SourceSchema;
use App\Upgrade\Steps\DiaryCommentUpgrade;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Runs the compiled diary-comment INSERT...SELECT against the real OpenPNE 3 `diary_comment` DDL.
 *
 * MySQL only: the set-based copy and the source DDL (TEXT, tinyint, DATETIME, utf8mb3)
 * are MySQL features. Uses DatabaseMigrations rather than RefreshDatabase because
 * creating the source table is DDL, which implicitly commits transactions.
 */
class DiaryCommentUpgradeSqlTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Upgrade INSERT...SELECT runs on MySQL (source DDL + set-based copy).');
        }

        // The real OpenPNE 3 `diary_comment` DDL, minus its FKs to `diary`/`member` so the
        // source table stands alone; the migrated `diaries`/`members` rows satisfy the
        // target-side FKs instead.
        DB::statement('DROP TABLE IF EXISTS `diary_comment`');
        DB::statement(SourceSchema::default()->createStatement('diary_comment', withoutForeignKeys: true));
    }

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('DROP TABLE IF EXISTS `diary_comment`');
        }

        parent::tearDown();
    }

    public function test_preserves_id_diary_author_number_and_timestamps(): void
    {
        $author = Member::factory()->create();
        $diary = Diary::factory()->create();
        $this->seedSourceComment(987, $diary->getKey(), $author->getKey(), ['number' => 3]);

        $this->runUpgrade();

        // id, number and timestamps come from the source row, not the upgrade run's clock.
        $this->assertDatabaseHas('diary_comments', [
            'id' => 987,
            'diary_id' => $diary->getKey(),
            'member_id' => $author->getKey(),
            'number' => 3,
            'created_at' => '2018-03-04 12:34:56',
            'updated_at' => '2019-06-07 01:02:03',
        ]);
    }

    public function test_keeps_comment_of_a_withdrawn_author_with_null_member(): void
    {
        // OpenPNE 3 sets member_id NULL when the author withdraws but keeps the comment.
        $diary = Diary::factory()->create();
        $this->seedSourceComment(1, $diary->getKey(), null);

        $this->runUpgrade();

        $this->assertNull(DiaryComment::findOrFail(1)->member_id);
    }

    public function test_preserves_long_text_body(): void
    {
        // OpenPNE 3 diary_comment.body is TEXT; a >255-char value must not truncate.
        $diary = Diary::factory()->create();
        $longBody = str_repeat('本文', 5000);
        $this->seedSourceComment(1, $diary->getKey(), null, ['body' => $longBody]);

        $this->runUpgrade();

        $this->assertSame($longBody, DiaryComment::findOrFail(1)->body);
    }

    public function test_imports_legacy_duplicate_number_losslessly(): void
    {
        // OpenPNE 3's `number` is a racy max+1 on a non-unique index, so legacy data can
        // carry duplicate (diary_id, number); the import must keep both rows.
        $diary = Diary::factory()->create();
        $this->seedSourceComment(1, $diary->getKey(), null, ['number' => 5]);
        $this->seedSourceComment(2, $diary->getKey(), null, ['number' => 5]);

        $this->runUpgrade();

        $this->assertDatabaseCount('diary_comments', 2);
        $this->assertSame(5, DiaryComment::findOrFail(1)->number);
        $this->assertSame(5, DiaryComment::findOrFail(2)->number);
    }

    private function runUpgrade(): void
    {
        DB::statement((new InsertSelectCompiler)->compile(new DiaryCommentUpgrade));
    }

    private function seedSourceComment(int $id, int $diaryId, ?int $memberId, array $overrides = []): void
    {
        DB::table('diary_comment')->insert(array_merge([
            'id' => $id,
            'diary_id' => $diaryId,
            'member_id' => $memberId,
            'number' => 1,
            'body' => 'Legacy comment',
            'has_images' => 0,
            'created_at' => '2018-03-04 12:34:56',
            'updated_at' => '2019-06-07 01:02:03',
        ], $overrides));
    }
}
