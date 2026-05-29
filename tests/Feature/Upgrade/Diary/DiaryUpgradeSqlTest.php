<?php

namespace Tests\Feature\Upgrade\Diary;

use App\Features\Diary\Visibility;
use App\Models\Diary;
use App\Models\Member;
use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\SourceSchema;
use App\Upgrade\Steps\DiaryUpgrade;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Runs the compiled diary INSERT...SELECT against the real OpenPNE 3 `diary` DDL.
 *
 * MySQL only: the set-based copy and the source DDL (TEXT, tinyint, DATETIME, utf8mb3)
 * are MySQL features. Uses DatabaseMigrations rather than RefreshDatabase because
 * creating the source table is DDL, which implicitly commits transactions.
 */
class DiaryUpgradeSqlTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Upgrade INSERT...SELECT runs on MySQL (source DDL + set-based copy).');
        }

        $this->createSourceDiaryTable();
    }

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('DROP TABLE IF EXISTS `diary`');
        }

        parent::tearDown();
    }

    public function test_preserves_id_owner_and_timestamps(): void
    {
        $owner = Member::factory()->create();
        $this->seedSourceDiary(4321, $owner->getKey());

        $this->runUpgrade();

        // id and timestamps come from the source row, not the upgrade run's clock.
        $this->assertDatabaseHas('diaries', [
            'id' => 4321,
            'member_id' => $owner->getKey(),
            'created_at' => '2018-03-04 12:34:56',
            'updated_at' => '2019-06-07 01:02:03',
        ]);
    }

    public function test_preserves_long_text_title_and_body(): void
    {
        // OpenPNE 3 diary.title/body are TEXT; a >255-char value must not truncate.
        $owner = Member::factory()->create();
        $longTitle = str_repeat('あ', 500);
        $longBody = str_repeat('本文', 5000);
        $this->seedSourceDiary(1, $owner->getKey(), ['title' => $longTitle, 'body' => $longBody]);

        $this->runUpgrade();

        $diary = Diary::findOrFail(1);
        $this->assertSame($longTitle, $diary->title);
        $this->assertSame($longBody, $diary->body);
    }

    public function test_maps_public_flag_and_is_open_to_visibility(): void
    {
        $owner = Member::factory()->create();
        $this->seedSourceDiary(1, $owner->getKey(), ['public_flag' => 1, 'is_open' => 0]);
        $this->seedSourceDiary(2, $owner->getKey(), ['public_flag' => 1, 'is_open' => 1]);
        $this->seedSourceDiary(3, $owner->getKey(), ['public_flag' => 2, 'is_open' => 0]);
        $this->seedSourceDiary(4, $owner->getKey(), ['public_flag' => 3, 'is_open' => 0]);
        $this->seedSourceDiary(5, $owner->getKey(), ['public_flag' => 4, 'is_open' => 0]);
        // is_open on a non-SNS row is anomalous; the restrictive flag wins.
        $this->seedSourceDiary(6, $owner->getKey(), ['public_flag' => 2, 'is_open' => 1]);

        $this->runUpgrade();

        $this->assertSame(Visibility::Members, Diary::findOrFail(1)->visibility);
        $this->assertSame(Visibility::Open, Diary::findOrFail(2)->visibility);
        $this->assertSame(Visibility::Friends, Diary::findOrFail(3)->visibility);
        $this->assertSame(Visibility::Private, Diary::findOrFail(4)->visibility);
        $this->assertSame(Visibility::Open, Diary::findOrFail(5)->visibility);
        $this->assertSame(Visibility::Friends, Diary::findOrFail(6)->visibility);
    }

    public function test_unknown_public_flag_aborts_the_copy(): void
    {
        // An unrecognised flag maps to NULL; the NOT NULL visibility column rejects it,
        // so the upgrade fails loudly instead of storing an out-of-range value.
        $owner = Member::factory()->create();
        $this->seedSourceDiary(1, $owner->getKey(), ['public_flag' => 99]);

        $this->expectException(QueryException::class);
        $this->runUpgrade();
    }

    private function createSourceDiaryTable(): void
    {
        // The real OpenPNE 3 `diary` DDL (TEXT, tinyint, DATETIME), minus its FK to
        // `member` so the source table stands alone in this diary-only test.
        DB::statement('DROP TABLE IF EXISTS `diary`');
        DB::statement(SourceSchema::default()->createStatement('diary', withoutForeignKeys: true));
    }

    private function runUpgrade(): void
    {
        DB::statement((new InsertSelectCompiler)->compile(new DiaryUpgrade));
    }

    private function seedSourceDiary(int $id, int $memberId, array $overrides = []): void
    {
        DB::table('diary')->insert(array_merge([
            'id' => $id,
            'member_id' => $memberId,
            'title' => 'Legacy title',
            'body' => 'Legacy body',
            'public_flag' => 1,
            'is_open' => 0,
            'has_images' => 0,
            'created_at' => '2018-03-04 12:34:56',
            'updated_at' => '2019-06-07 01:02:03',
        ], $overrides));
    }
}
