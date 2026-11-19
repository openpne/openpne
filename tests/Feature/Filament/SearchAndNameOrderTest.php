<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\AdminUsers\Pages\ListAdminUsers;
use App\Filament\Resources\Diaries\Pages\ListDiaries;
use App\Filament\Resources\GroupEvents\Pages\ListGroupEvents;
use App\Filament\Resources\Groups\GroupResource;
use App\Filament\Resources\GroupTopics\Pages\ListGroupTopics;
use App\Filament\Resources\Members\Pages\ListMembers;
use App\Models\AdminUser;
use App\Models\Diary;
use App\Models\Group;
use App\Models\GroupEvent;
use App\Models\GroupTopic;
use App\Models\Member;
use Closure;
use Filament\Actions\AssociateAction;
use Filament\Actions\AttachAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\QueryBuilder\Constraints\RelationshipConstraint\Operators\IsRelatedToOperator;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class SearchAndNameOrderTest extends TestCase
{
    use RefreshDatabase;

    private AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->admin = AdminUser::factory()->create();
        $this->actingAs($this->admin, 'admin');
    }

    public function test_a_table_search_folds_ascii_case(): void
    {
        $member = Member::factory()->create(['name' => 'tanaka']);

        Livewire::test(ListMembers::class)->searchTable('TANAKA')->assertCanSeeTableRecords([$member]);
    }

    public function test_mysql_table_search_folds_kana_but_tells_the_voiced_mark_apart(): void
    {
        $this->skipUnlessMysql();
        $katakana = Member::factory()->create(['name' => 'タナカ']);
        $voiced = Member::factory()->create(['name' => 'ば']);

        Livewire::test(ListMembers::class)->searchTable('たなか')->assertCanSeeTableRecords([$katakana]);
        Livewire::test(ListMembers::class)->searchTable('は')->assertCanNotSeeTableRecords([$voiced]);
    }

    public function test_the_global_search_folds_ascii_case(): void
    {
        Group::factory()->create(['name' => 'tanaka']);

        $this->assertContains('tanaka', $this->globalSearchTitles('TANAKA'));
    }

    public function test_mysql_global_search_folds_kana_but_tells_the_voiced_mark_apart(): void
    {
        $this->skipUnlessMysql();
        Group::factory()->create(['name' => 'タナカ']);
        Group::factory()->create(['name' => 'ば']);

        $this->assertContains('タナカ', $this->globalSearchTitles('たなか'));
        $this->assertNotContains('ば', $this->globalSearchTitles('は'));
    }

    public function test_every_search_filament_offers_folds_case_where_a_search_collation_is_configured(): void
    {
        $resources = array_filter(
            Filament::getPanel('admin')->getResources(),
            fn (string $resource): bool => $resource::getGloballySearchableAttributes() !== [],
        );
        $this->assertNotEmpty($resources);
        $components = [
            TextColumn::make('probe'),
            Select::make('probe'),
            SelectFilter::make('probe'),
            AttachAction::make(),
            AssociateAction::make(),
            IsRelatedToOperator::make(),
        ];

        $this->withDefaultConnection(config('database.connections.mysql'), function () use ($resources, $components): void {
            foreach ($resources as $resource) {
                $this->assertTrue($resource::isGlobalSearchForcedCaseInsensitive(), "{$resource}'s global search");
            }
            foreach ($components as $component) {
                $this->assertTrue($component->isSearchForcedCaseInsensitive(), $component::class);
            }
        });

        $this->withDefaultConnection(['driver' => 'sqlite', 'database' => ':memory:'], function () use ($resources, $components): void {
            foreach ($resources as $resource) {
                $this->assertNull($resource::isGlobalSearchForcedCaseInsensitive(), "{$resource}'s global search");
            }
            foreach ($components as $component) {
                $this->assertNull($component->isSearchForcedCaseInsensitive(), $component::class);
            }
        });
    }

    public function test_admin_users_default_to_name_order(): void
    {
        $admins = [];
        foreach ($this->unorderedNames() as $name) {
            $admins[$name] = AdminUser::factory()->create(['username' => $name]);
        }

        $this->assertOrder($admins, Livewire::test(ListAdminUsers::class));
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
     * Each engine's plain order puts these differently: SQLite by code point (Tanaka before abe),
     * MySQL's utf8mb4_unicode_ci by code point within the kanji (亜, 愛, 阿).
     *
     * @return list<string>
     */
    private function unorderedNames(): array
    {
        return DB::connection()->getDriverName() === 'mysql' ? ['愛', '阿', '亜'] : ['たなか', 'Tanaka', 'abe'];
    }

    /** @param  array<string, Model>  $byName */
    private function assertOrder(array $byName, Testable $list): void
    {
        $expected = DB::connection()->getDriverName() === 'mysql' ? ['亜', '阿', '愛'] : ['abe', 'Tanaka', 'たなか'];
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

    /** @param  array<string, mixed>  $connection */
    private function withDefaultConnection(array $connection, Closure $run): void
    {
        $default = config('database.default');
        // Connections open lazily, so the probe never reaches a server.
        config(['database.connections.search_probe' => $connection, 'database.default' => 'search_probe']);

        try {
            $run();
        } finally {
            config(['database.default' => $default]);
            DB::purge('search_probe');
        }
    }

    private function skipUnlessMysql(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('mysql only');
        }
    }
}
