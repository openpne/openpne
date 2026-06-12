<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\Navigations\Pages\CreateNavigation;
use App\Filament\Resources\Navigations\Pages\EditNavigation;
use App\Filament\Resources\Navigations\Pages\ListNavigations;
use App\Models\AdminUser;
use App\Models\Navigation;
use App\Services\NavigationService;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class NavigationResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->actingAs(AdminUser::factory()->create(), 'admin');
    }

    private function makeNav(string $type, string $uri): Navigation
    {
        $nav = Navigation::create(['type' => $type, 'uri' => $uri, 'sort_order' => 0]);
        $nav->setTranslation('ja_JP', 'キャプション');

        return $nav;
    }

    public function test_list_tab_shows_only_its_type(): void
    {
        $member = $this->makeNav('secure_global', '/member/search');
        $local = $this->makeNav('default', '/friend/list');

        Livewire::test(ListNavigations::class)
            ->set('activeTab', 'secure_global')
            ->assertCanSeeTableRecords([$member])
            ->assertCanNotSeeTableRecords([$local]);
    }

    public function test_creates_an_item_with_both_captions_and_clears_cache(): void
    {
        Livewire::test(CreateNavigation::class)
            ->fillForm([
                'type' => 'secure_global',
                'uri' => '/member/search',
                'caption_ja' => 'メンバー検索',
                'caption_en' => 'Search Members',
                'sort_order' => 10,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $nav = Navigation::query()->where('uri', '/member/search')->first();
        $this->assertNotNull($nav);
        $this->assertSame('メンバー検索', $nav->getCaption('ja_JP'));
        $this->assertSame('Search Members', $nav->getCaption('en'));

        // cache was cleared, so the renderer sees the new item immediately
        $items = app(NavigationService::class)->visibleEntries('secure_global', 'ja');
        $this->assertSame('メンバー検索', $items[0]['label']);
    }

    public function test_uri_validation_rejects_unconverted_and_unsafe_values(): void
    {
        foreach (['@homepage', 'diary/index', '//example.com', 'ftp://example.com'] as $bad) {
            Livewire::test(CreateNavigation::class)
                ->fillForm(['type' => 'secure_global', 'uri' => $bad, 'caption_ja' => 'x'])
                ->call('create')
                ->assertHasFormErrors(['uri']);
        }
    }

    public function test_id_placeholder_is_only_allowed_in_member_or_community_types(): void
    {
        Livewire::test(CreateNavigation::class)
            ->fillForm(['type' => 'secure_global', 'uri' => '/member/:id', 'caption_ja' => 'x'])
            ->call('create')
            ->assertHasFormErrors(['uri']);

        Livewire::test(CreateNavigation::class)
            ->fillForm(['type' => 'friend', 'uri' => '/member/:id', 'caption_ja' => 'x'])
            ->call('create')
            ->assertHasNoFormErrors();
    }

    public function test_edit_updates_caption(): void
    {
        $nav = $this->makeNav('default', '/friend/list');

        Livewire::test(EditNavigation::class, ['record' => $nav->getKey()])
            ->fillForm(['caption_ja' => '更新後'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('更新後', $nav->fresh()->getCaption('ja_JP'));
    }

    public function test_delete_cascades_translations(): void
    {
        $nav = $this->makeNav('default', '/friend/list');

        Livewire::test(EditNavigation::class, ['record' => $nav->getKey()])
            ->callAction(DeleteAction::class);

        $this->assertModelMissing($nav);
        $this->assertDatabaseMissing('navigation_translations', ['id' => $nav->getKey()]);
    }
}
