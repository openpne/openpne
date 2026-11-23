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
            'the half-width prolonged sound mark is not the full-width one' => ['ｺｰﾋｰ', 'コーヒー', false],
            'a half-width semi-voiced kana is not its full-width one' => ['ﾊﾟﾝ', 'パン', false],
            'the half-width middle dot is not the full-width one' => ['ｱ･ｲ', 'ア・イ', false],
            'an accent is told apart' => ['José', 'jose', false],
        ];
    }

    #[DataProvider('mysqlMatches')]
    public function test_mysql_matches_through_the_japanese_collation(string $stored, string $term, bool $matches): void
    {
        $this->skipUnlessDriver('mysql');

        $this->assertSame($matches, $this->found($stored, $term));
    }

    /** @return array<string, array{string, string, bool}> stored name, typed term, whether it matches */
    public static function mariadbMatches(): array
    {
        return [
            'uppercase finds lowercase' => ['tanaka', 'TANAKA', true],
            'case outside ASCII is lowered' => ['ÉCOLE', 'école', true],
            'a small sharp s finds the capital one' => ['STRAẞE', 'straße', true],
            'a capital sharp s finds the small one' => ['straße', 'STRAẞE', true],
            'Deseret case is lowered' => ['𐐀', '𐐨', true],
            'a final sigma does not find the capital one' => ['Σ', 'ς', false],
            'a long s does not find the capital one' => ['S', 'ſ', false],
            'hiragana does not find katakana' => ['タナカ', 'たなか', false],
            'full-width letters do not find half-width' => ['Tanaka', 'ＴＡＮＡＫＡ', false],
            'a half-width kana is not its full-width one' => ['ｱ', 'ア', false],
            'the voiced mark is told apart' => ['ば', 'は', false],
            'a small kana is told apart' => ['きょう', 'きよう', false],
            'an accent is told apart' => ['José', 'jose', false],
            'two emoji are told apart' => ['🍣', '🍺', false],
            'a skin tone is told apart' => ['👍🏻', '👍🏿', false],
        ];
    }

    #[DataProvider('mariadbMatches')]
    public function test_mariadb_search_matches(string $stored, string $term, bool $matches): void
    {
        $this->skipUnlessDriver('mariadb');

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

    public function test_a_typed_term_takes_its_wildcards_literally(): void
    {
        $percent = Member::factory()->create(['name' => '100%_!']);
        Member::factory()->create(['name' => '1000']);
        Member::factory()->create(['name' => '100x_!']);

        $found = SearchCollation::whereContains(Member::query(), 'name', '0%_!')->pluck('id')->all();

        $this->assertSame([$percent->getKey()], $found);
    }

    public function test_names_order_ignoring_ascii_case_on_both_engines(): void
    {
        foreach (['Tanaka', 'たなか', 'abe'] as $name) {
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

        // Code point order is 亜 < 愛 < 阿.
        $this->assertSame(['亜', '阿', '愛'], $this->namesInOrder());
    }

    public function test_mariadb_orders_the_main_kanji_block_by_code_point_ahead_of_its_extensions(): void
    {
        $this->skipUnlessDriver('mariadb');
        foreach (['愛', '阿', '㐂', '亜'] as $name) {
            Member::factory()->create(['name' => $name]);
        }

        $this->assertSame(['亜', '愛', '阿', '㐂'], $this->namesInOrder());
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
