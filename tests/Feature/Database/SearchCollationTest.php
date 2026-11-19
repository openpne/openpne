<?php

namespace Tests\Feature\Database;

use App\Models\Diary;
use App\Models\Member;
use App\Support\Database\SearchCollation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SearchCollationTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{string, string, bool}> stored name, typed term, whether it matches */
    public static function mysqlMatches(): array
    {
        return [
            'hiragana finds katakana' => ['タナカ', 'たなか', true],
            'full-width letters find half-width' => ['Tanaka', 'ＴＡＮＡＫＡ', true],
            'uppercase finds lowercase' => ['tanaka', 'TANAKA', true],
            'a half-width kana finds its full-width one' => ['カ', 'ｶ', true],
            'the voiced mark is told apart' => ['ば', 'は', false],
            'the semi-voiced mark is told apart' => ['ぱ', 'は', false],
            'a small kana is told apart' => ['きょう', 'きよう', false],
            // The half-width voiced mark is a character of its own, so ｶﾞ is not ガ.
            'a half-width voiced kana is not its full-width one' => ['ｶﾞｸｾｲ', 'ガ', false],
        ];
    }

    #[DataProvider('mysqlMatches')]
    public function test_mysql_matches_through_the_japanese_collation(string $stored, string $term, bool $matches): void
    {
        $this->skipUnlessDriver('mysql');

        $this->assertSame($matches, $this->found($stored, $term));
    }

    /** @return array<string, array{string, string, bool}> stored name, typed term, whether it matches */
    public static function sqliteMatches(): array
    {
        return [
            'uppercase finds lowercase' => ['tanaka', 'TANAKA', true],
            'hiragana does not find katakana' => ['タナカ', 'たなか', false],
            'full-width letters do not find half-width' => ['Tanaka', 'ＴＡＮＡＫＡ', false],
        ];
    }

    #[DataProvider('sqliteMatches')]
    public function test_sqlite_folds_only_ascii_case(string $stored, string $term, bool $matches): void
    {
        $this->skipUnlessDriver('sqlite');

        $this->assertSame($matches, $this->found($stored, $term));
    }

    public function test_an_escape_character_neutralizes_a_wildcard(): void
    {
        $percent = Member::factory()->create(['name' => '100%']);
        Member::factory()->create(['name' => '1000']);

        $found = SearchCollation::whereMatches(Member::query(), 'name', '%0!%%', '!')->pluck('id')->all();

        $this->assertSame([$percent->getKey()], $found);
    }

    public function test_names_order_ignoring_ascii_case_on_both_engines(): void
    {
        foreach (['たなか', 'Tanaka', 'abe'] as $name) {
            Member::factory()->create(['name' => $name]);
        }

        // Code-point order, the engine's plain one on SQLite, would put Tanaka first.
        $this->assertSame(['abe', 'Tanaka', 'たなか'], $this->namesInOrder());
        $this->assertSame(['たなか', 'Tanaka', 'abe'], $this->namesInOrder('desc'));
    }

    public function test_mysql_orders_kanji_by_the_japanese_collation(): void
    {
        $this->skipUnlessDriver('mysql');
        foreach (['愛', '阿', '亜'] as $name) {
            Member::factory()->create(['name' => $name]);
        }

        // utf8mb4_unicode_ci alone sorts these 亜 < 愛 < 阿.
        $this->assertSame(['亜', '阿', '愛'], $this->namesInOrder());
    }

    public function test_a_subquery_orders_through_the_same_collation(): void
    {
        $names = DB::connection()->getDriverName() === 'mysql' ? ['愛', '阿', '亜'] : ['Tanaka', 'abe', 'たなか'];
        $diaryIds = [];
        foreach ($names as $name) {
            $diaryIds[$name] = Diary::factory()->create(['member_id' => Member::factory()->create(['name' => $name])])->getKey();
        }

        $ordered = SearchCollation::orderBy(
            Diary::query(),
            DB::table('members')->select('members.name')->whereColumn('members.id', 'diaries.member_id'),
        )->pluck('id')->all();

        $expected = DB::connection()->getDriverName() === 'mysql' ? ['亜', '阿', '愛'] : ['abe', 'Tanaka', 'たなか'];
        $this->assertSame(array_map(fn (string $name): int => $diaryIds[$name], $expected), $ordered);
    }

    public function test_filament_is_told_to_fold_case_only_where_a_search_collation_is_configured(): void
    {
        $default = config('database.default');
        // Connections open lazily, so neither probe reaches a server.
        config([
            'database.connections.probe_mysql' => config('database.connections.mysql'),
            'database.connections.probe_sqlite' => ['driver' => 'sqlite', 'database' => ':memory:'],
        ]);

        try {
            config(['database.default' => 'probe_mysql']);
            $this->assertTrue(SearchCollation::forcesCaseFolding());

            config(['database.default' => 'probe_sqlite']);
            $this->assertNull(SearchCollation::forcesCaseFolding());
        } finally {
            config(['database.default' => $default]);
            DB::purge('probe_mysql');
            DB::purge('probe_sqlite');
        }
    }

    private function found(string $stored, string $term): bool
    {
        $member = Member::factory()->create(['name' => $stored]);

        return SearchCollation::whereMatches(Member::query(), 'name', '%'.$term.'%')->whereKey($member->getKey())->exists();
    }

    /** @return list<string> */
    private function namesInOrder(string $direction = 'asc'): array
    {
        return SearchCollation::orderBy(Member::query(), 'name', $direction)->pluck('name')->all();
    }

    private function skipUnlessDriver(string $driver): void
    {
        if (DB::connection()->getDriverName() !== $driver) {
            $this->markTestSkipped("{$driver} only");
        }
    }
}
