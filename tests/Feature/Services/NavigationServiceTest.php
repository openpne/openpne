<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\Member;
use App\Models\Navigation;
use App\Services\NavigationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NavigationServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeNav(string $type, string $uri, ?string $sourceUri, array $captions, int $sort = 0): Navigation
    {
        $nav = Navigation::create(['type' => $type, 'uri' => $uri, 'source_uri' => $sourceUri, 'sort_order' => $sort]);
        foreach ($captions as $lang => $caption) {
            $nav->setTranslation($lang, $caption);
        }
        app(NavigationService::class)->clearCache();

        return $nav;
    }

    public function test_resolves_caption_terms_and_derives_dom_id_from_source_uri(): void
    {
        $this->makeNav('default', '/friend/list', '@friend_list', ['ja_JP' => '%my_friend%', 'en' => 'My Friends']);

        $items = app(NavigationService::class)->visibleEntries('default', 'ja');

        $this->assertCount(1, $items);
        $this->assertSame('default__friend_list', $items[0]['domId']);
        $this->assertSame(url('/friend/list'), $items[0]['href']);
        $this->assertSame('マイフレンド', $items[0]['label']); // %my_friend% resolved via TermService
        $this->assertFalse($items[0]['isPostLogout']);
    }

    public function test_threads_subject_id_into_placeholder_or_query(): void
    {
        $this->makeNav('friend', '/member/:id', '@member_profile', ['en' => 'Home'], 0);
        $this->makeNav('friend', '/friend/list', '@friend_list', ['en' => 'Friends'], 1);

        $items = app(NavigationService::class)->visibleEntries('friend', 'en', 42);

        $this->assertSame(url('/member/42'), $items[0]['href']); // :id substituted
        $this->assertSame(url('/friend/list?id=42'), $items[1]['href']); // no :id → ?id= appended
    }

    public function test_logout_item_is_flagged_for_a_post_form(): void
    {
        $this->makeNav('secure_global', '/logout', '@member_logout', ['en' => 'Logout']);

        $items = app(NavigationService::class)->visibleEntries('secure_global', 'en');

        $this->assertCount(1, $items);
        $this->assertTrue($items[0]['isPostLogout']);
        $this->assertSame(url('/logout'), $items[0]['href']);
    }

    public function test_hides_unresolved_missing_route_and_shim_items(): void
    {
        $this->makeNav('secure_global', '@homepage', '@homepage', ['en' => 'Unconverted'], 0); // not normalized
        $this->makeNav('secure_global', '/diary', 'diary/index', ['en' => 'Diary'], 1); // no route
        $this->makeNav('secure_global', '/member/config', '@member_config', ['en' => 'Settings'], 2); // shim

        $items = app(NavigationService::class)->visibleEntries('secure_global', 'en');

        $this->assertSame([], $items);
    }

    public function test_external_url_is_kept_without_a_route_check(): void
    {
        $this->makeNav('secure_global', 'https://example.com/help', null, ['en' => 'Help']);

        $items = app(NavigationService::class)->visibleEntries('secure_global', 'en');

        $this->assertCount(1, $items);
        $this->assertSame('https://example.com/help', $items[0]['href']);
        $this->assertSame('globalNav_https___example.com_help', $items[0]['domId']);
    }

    public function test_renders_a_reachable_internal_item_for_a_real_member_route(): void
    {
        Member::factory()->create();
        $this->makeNav('secure_global', '/member/search', '@member_search', ['en' => 'Search Members']);

        $items = app(NavigationService::class)->visibleEntries('secure_global', 'en');

        $this->assertCount(1, $items);
        $this->assertSame(url('/member/search'), $items[0]['href']);
    }
}
