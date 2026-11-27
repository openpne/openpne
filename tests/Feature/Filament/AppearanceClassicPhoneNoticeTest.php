<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\BannerImages\BannerImageResource;
use App\Filament\Resources\Gadgets\GadgetResource;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppearanceClassicPhoneNoticeTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE_CLAUSE = 'スマホからの閲覧は常にモダン';

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->actingAsAdmin();
        $this->withSession(['locale' => 'ja']);
    }

    /**
     * Read from the built navigation, so a screen added to the group later is covered without editing this list.
     *
     * @return list<string>
     */
    private function appearanceUrls(): array
    {
        app()->setLocale('ja');
        $group = collect(Filament::getCurrentPanel()->getNavigation())
            ->first(fn ($group) => $group->getLabel() === __('Appearance (Classic)'));
        $this->assertNotNull($group);

        $urls = collect($group->getItems())->map(fn (NavigationItem $item): string => (string) $item->getUrl())->values()->all();
        $this->assertGreaterThanOrEqual(7, count($urls));

        return $urls;
    }

    public function test_every_appearance_menu_screen_says_phones_get_modern_while_both_surfaces_are_served(): void
    {
        config(['openpne.surface_mode' => 'classic_default']);

        foreach ($this->appearanceUrls() as $url) {
            $this->get($url)->assertOk()->assertSee(self::PHONE_CLAUSE, false);
        }
    }

    public function test_no_appearance_menu_screen_mentions_phones_under_classic_only(): void
    {
        config(['openpne.surface_mode' => 'classic_only']);

        foreach ($this->appearanceUrls() as $url) {
            $this->get($url)->assertOk()->assertDontSee('スマホ', false)->assertDontSee('モダン', false);
        }
    }

    public function test_the_gadget_list_points_at_the_modern_home_notice_and_login_message(): void
    {
        config(['openpne.surface_mode' => 'modern_default']);

        $this->get(GadgetResource::getUrl('index'))
            ->assertOk()
            ->assertSee('モダンのホームとログイン画面には、ガジェットとは別のお知らせとメッセージが出ます。', false)
            ->assertDontSee('スマホには届きません', false);
    }

    public function test_the_banner_image_list_keeps_its_own_subheading_beside_the_notice(): void
    {
        config(['openpne.surface_mode' => 'classic_default']);

        $this->get(BannerImageResource::getUrl('index'))
            ->assertOk()
            ->assertSee('各画像をどこに表示するかは「バナー」ページで選びます。バナーはクラシック画面にだけ表示され', false);

        config(['openpne.surface_mode' => 'classic_only']);

        $this->get(BannerImageResource::getUrl('index'))
            ->assertOk()
            ->assertSee('各画像をどこに表示するかは「バナー」ページで選びます。', false);
    }
}
