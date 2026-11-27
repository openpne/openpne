<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Diaries\Pages\ListDiaries;
use App\Filament\Resources\DiaryComments\Pages\ListDiaryComments;
use App\Filament\Resources\Files\Pages\ListFiles;
use App\Filament\Resources\GroupCategories\Pages\ListGroupCategories;
use App\Filament\Resources\GroupEvents\Pages\ListGroupEvents;
use App\Filament\Resources\Groups\GroupResource;
use App\Filament\Resources\Groups\Pages\ListGroups;
use App\Filament\Resources\GroupTopics\Pages\ListGroupTopics;
use App\Filament\Resources\Members\Pages\ListMembers;
use App\Filament\Resources\Profiles\Pages\ListProfiles;
use App\Models\Diary;
use App\Models\Group;
use App\Models\GroupEvent;
use App\Models\GroupTopic;
use App\Models\Member;
use Filament\Facades\Filament;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SearchAndNameOrderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->actingAsAdmin();
    }

    public function test_a_table_search_folds_ascii_case(): void
    {
        $member = Member::factory()->create(['name' => 'tanaka']);

        Livewire::test(ListMembers::class)->searchTable('TANAKA')->assertCanSeeTableRecords([$member]);
    }

    public function test_each_word_must_match_some_searchable_column(): void
    {
        $both = Member::factory()->create(['name' => 'tanaka', 'email' => 'taro@example.com']);
        $nameOnly = Member::factory()->create(['name' => 'tanaka', 'email' => 'jiro@example.com']);

        Livewire::test(ListMembers::class)->searchTable('TANAKA taro')
            ->assertCanSeeTableRecords([$both])
            ->assertCanNotSeeTableRecords([$nameOnly]);
    }

    public function test_mysql_table_search_folds_kana_but_tells_the_voiced_mark_apart(): void
    {
        $this->skipUnlessDriver('mysql');
        $katakana = Member::factory()->create(['name' => 'タナカ']);
        $voiced = Member::factory()->create(['name' => 'ば']);

        Livewire::test(ListMembers::class)->searchTable('たなか')->assertCanSeeTableRecords([$katakana]);
        Livewire::test(ListMembers::class)->searchTable('は')->assertCanNotSeeTableRecords([$voiced]);
    }

    public function test_mariadb_table_search_tells_kana_and_the_voiced_mark_apart(): void
    {
        $this->skipUnlessDriver('mariadb');
        $katakana = Member::factory()->create(['name' => 'タナカ']);
        $voiced = Member::factory()->create(['name' => 'ば']);

        Livewire::test(ListMembers::class)->searchTable('たなか')->assertCanNotSeeTableRecords([$katakana]);
        Livewire::test(ListMembers::class)->searchTable('は')->assertCanNotSeeTableRecords([$voiced]);
    }

    public function test_the_global_search_folds_ascii_case(): void
    {
        Group::factory()->create(['name' => 'tanaka']);

        $this->assertContains('tanaka', $this->globalSearchTitles('TANAKA'));
    }

    public function test_mysql_global_search_folds_kana_but_tells_the_voiced_mark_apart(): void
    {
        $this->skipUnlessDriver('mysql');
        Group::factory()->create(['name' => 'タナカ']);
        Group::factory()->create(['name' => 'ば']);

        $this->assertContains('タナカ', $this->globalSearchTitles('たなか'));
        $this->assertNotContains('ば', $this->globalSearchTitles('は'));
    }

    public function test_mariadb_global_search_tells_kana_and_the_voiced_mark_apart(): void
    {
        $this->skipUnlessDriver('mariadb');
        Group::factory()->create(['name' => 'タナカ']);
        Group::factory()->create(['name' => 'ば']);

        $this->assertNotContains('タナカ', $this->globalSearchTitles('たなか'));
        $this->assertNotContains('ば', $this->globalSearchTitles('は'));
    }

    /**
     * PHP's and MySQL's lower case differ for these, so lowering the term in PHP and the column in
     * SQL misses the very name that was typed.
     *
     * @return array<string, array{string}>
     */
    public static function names(): array
    {
        return [
            'dotted capital I' => ['İstanbul'],
            'final sigma' => ['ΟΣ'],
            'Deseret' => ['𐐀'],
            'Georgian' => ['Ⴀ'],
            'umlaut' => ['ÄRZTE'],
            'full-width' => ['ＡＢＣ部'],
            'acute' => ['Émile'],
        ];
    }

    #[DataProvider('names')]
    public function test_a_name_finds_itself_in_a_table_and_the_global_search(string $name): void
    {
        $member = Member::factory()->create(['name' => $name]);
        Group::factory()->create(['name' => $name]);

        Livewire::test(ListMembers::class)->searchTable($name)->assertCanSeeTableRecords([$member]);
        $this->assertContains($name, $this->globalSearchTitles($name));
    }

    /** @return array<string, array{class-string}> */
    public static function lists(): array
    {
        return [
            'members' => [ListMembers::class],
            'diaries' => [ListDiaries::class],
            'diary comments' => [ListDiaryComments::class],
            'groups' => [ListGroups::class],
            'group categories' => [ListGroupCategories::class],
            'topics' => [ListGroupTopics::class],
            'events' => [ListGroupEvents::class],
            'files' => [ListFiles::class],
            'profile fields' => [ListProfiles::class],
        ];
    }

    /** Filament's own search SQL ends in escape '!'; the helper binds its escape character. */
    #[DataProvider('lists')]
    public function test_every_table_search_goes_through_the_helper(string $list): void
    {
        DB::enableQueryLog();
        Livewire::test($list)->searchTable('x')->assertOk();
        $searches = array_filter(array_column(DB::getQueryLog(), 'query'), fn (string $sql): bool => stripos($sql, ' like ') !== false);

        $this->assertNotEmpty($searches);
        foreach ($searches as $sql) {
            $this->assertStringContainsString('ESCAPE ?', $sql);
            $this->assertStringNotContainsStringIgnoringCase("escape '!'", $sql);
        }
    }

    public function test_every_global_search_goes_through_the_helper(): void
    {
        $resources = array_filter(
            Filament::getPanel('admin')->getResources(),
            fn (string $resource): bool => $resource::getGloballySearchableAttributes() !== [],
        );
        $this->assertNotEmpty($resources);

        foreach ($resources as $resource) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $resource::getGlobalSearchResults('x');
            $searches = array_filter(array_column(DB::getQueryLog(), 'query'), fn (string $sql): bool => stripos($sql, ' like ') !== false);

            $this->assertNotEmpty($searches, $resource);
            foreach ($searches as $sql) {
                $this->assertStringContainsString('ESCAPE ?', $sql, $resource);
                $this->assertStringNotContainsStringIgnoringCase("escape '!'", $sql, $resource);
            }
        }
    }

    public function test_members_sort_by_name(): void
    {
        $members = [];
        foreach ($this->unorderedNames() as $name) {
            $members[$name] = Member::factory()->create(['name' => $name]);
        }

        $this->assertOrder($members, Livewire::test(ListMembers::class)->sortTable('name'));
    }

    public function test_diaries_sort_by_their_writers_name(): void
    {
        $diaries = [];
        foreach ($this->unorderedNames() as $name) {
            $diaries[$name] = Diary::factory()->create(['member_id' => Member::factory()->create(['name' => $name])]);
        }

        $this->assertOrder($diaries, Livewire::test(ListDiaries::class)->sortTable('member.name'));
    }

    public function test_topics_and_events_sort_by_their_groups_name(): void
    {
        $topics = $events = [];
        foreach ($this->unorderedNames() as $name) {
            $group = Group::factory()->create(['name' => $name]);
            $topics[$name] = GroupTopic::factory()->create(['group_id' => $group->getKey()]);
            $events[$name] = GroupEvent::factory()->create(['group_id' => $group->getKey()]);
        }

        $this->assertOrder($topics, Livewire::test(ListGroupTopics::class)->sortTable('group.name'));
        $this->assertOrder($events, Livewire::test(ListGroupEvents::class)->sortTable('group.name'));
    }

    /**
     * Created in neither the expected order nor its reverse (the tables' default is newest first),
     * and in an order each engine's plain comparison does not give: SQLite's and MariaDB's binary
     * comparison put Tanaka before abe, and MySQL's puts 愛 before 阿.
     *
     * @return list<string>
     */
    private function unorderedNames(): array
    {
        return match (DB::connection()->getDriverName()) {
            'mysql' => ['阿', '愛', '亜'],
            'mariadb', 'sqlite' => ['Tanaka', 'たなか', 'abe'],
        };
    }

    /** @param  array<string, Model>  $byName */
    private function assertOrder(array $byName, Testable $list): void
    {
        $expected = match (DB::connection()->getDriverName()) {
            'mysql' => ['亜', '阿', '愛'],
            'mariadb', 'sqlite' => ['abe', 'Tanaka', 'たなか'],
        };
        $records = $list->instance()->getTableRecords();
        $ids = collect($records instanceof Paginator ? $records->items() : $records)
            ->map(fn (Model $record): mixed => $record->getKey())
            ->intersect(array_map(fn (Model $model): mixed => $model->getKey(), $byName))
            ->values()
            ->all();

        $this->assertSame(array_map(fn (string $name): mixed => $byName[$name]->getKey(), $expected), $ids);
    }

    /** @return list<string> */
    private function globalSearchTitles(string $search): array
    {
        return GroupResource::getGlobalSearchResults($search)->map(fn ($result): string => (string) $result->title)->all();
    }

    private function skipUnlessDriver(string $driver): void
    {
        if (DB::connection()->getDriverName() !== $driver) {
            $this->markTestSkipped("{$driver} only");
        }
    }
}
