<?php

namespace Database\Seeders;

use App\Models\Navigation;
use App\Services\NavigationService;
use Illuminate\Database\Seeder;

/**
 * OpenPNE 3's default PC navigation set, with each uri normalized to its OpenPNE 4 URL (the form
 * the upgrade tool also produces) and `source_uri` keeping the original OpenPNE 3 value for DOM-id
 * compatibility. Items pointing at not-yet-ported features (`/diary` index) or compatibility shims
 * (`/member/config`) are seeded faithfully and stay hidden by the renderer's route check until
 * those features land. The community set is seeded now and rendered once the community localNav
 * context is wired.
 */
class NavigationSeeder extends Seeder
{
    /**
     * @var list<array{type: string, uri: string, source_uri: string, sort_order: int, ja: string, en: string}>
     */
    private const ITEMS = [
        // secure_global (members)
        ['type' => 'secure_global', 'uri' => '/', 'source_uri' => '@homepage', 'sort_order' => 0, 'ja' => 'マイホーム', 'en' => 'My Home'],
        ['type' => 'secure_global', 'uri' => '/member/search', 'source_uri' => '@member_search', 'sort_order' => 10, 'ja' => 'メンバー検索', 'en' => 'Search Members'],
        ['type' => 'secure_global', 'uri' => '/community/search', 'source_uri' => '@community_search', 'sort_order' => 20, 'ja' => '%community%検索', 'en' => 'Search Communities'],
        ['type' => 'secure_global', 'uri' => '/diary', 'source_uri' => 'diary/index', 'sort_order' => 25, 'ja' => '日記', 'en' => 'Diary'],
        ['type' => 'secure_global', 'uri' => '/member/config', 'source_uri' => '@member_config', 'sort_order' => 30, 'ja' => '設定変更', 'en' => 'Settings'],
        ['type' => 'secure_global', 'uri' => '/invite', 'source_uri' => '@member_invite', 'sort_order' => 40, 'ja' => '%friend%を招待する', 'en' => 'Invite'],
        ['type' => 'secure_global', 'uri' => '/logout', 'source_uri' => '@member_logout', 'sort_order' => 50, 'ja' => 'ログアウト', 'en' => 'Logout'],

        // default (the viewer's own pages)
        ['type' => 'default', 'uri' => '/', 'source_uri' => '@homepage', 'sort_order' => 0, 'ja' => 'ホーム', 'en' => 'Home'],
        ['type' => 'default', 'uri' => '/friend/list', 'source_uri' => '@friend_list', 'sort_order' => 10, 'ja' => '%my_friend%', 'en' => 'My Friends'],
        ['type' => 'default', 'uri' => '/diary/listMember', 'source_uri' => 'diary/listMember', 'sort_order' => 15, 'ja' => '日記', 'en' => 'Diary'],
        ['type' => 'default', 'uri' => '/member/profile', 'source_uri' => '@member_profile_mine', 'sort_order' => 20, 'ja' => 'プロフィール確認', 'en' => 'Profile'],
        ['type' => 'default', 'uri' => '/member/edit/profile', 'source_uri' => '@member_editProfile', 'sort_order' => 30, 'ja' => 'プロフィール編集', 'en' => 'Edit Profile'],

        // friend (a page about another member; :id is threaded in at render)
        ['type' => 'friend', 'uri' => '/member/:id', 'source_uri' => '@member_profile', 'sort_order' => 10, 'ja' => 'ホーム', 'en' => 'Home'],
        ['type' => 'friend', 'uri' => '/diary/listMember/:id', 'source_uri' => 'diary/listMember', 'sort_order' => 15, 'ja' => '日記', 'en' => 'Diary'],
        ['type' => 'friend', 'uri' => '/friend/list', 'source_uri' => '@friend_list', 'sort_order' => 20, 'ja' => '%friend%リスト', 'en' => 'Friends'],

        // community (rendered once the community localNav context is wired)
        ['type' => 'community', 'uri' => '/community/:id', 'source_uri' => '@community_home', 'sort_order' => 0, 'ja' => '%community%トップ', 'en' => '%Community% Top'],
        ['type' => 'community', 'uri' => '/communityTopic/listCommunity/:id', 'source_uri' => 'communityTopic/listCommunity', 'sort_order' => 5, 'ja' => 'トピックリスト', 'en' => 'Topics'],
        ['type' => 'community', 'uri' => '/communityEvent/listCommunity/:id', 'source_uri' => 'communityEvent/listCommunity', 'sort_order' => 6, 'ja' => 'イベントリスト', 'en' => 'Events'],
        ['type' => 'community', 'uri' => '/community/join', 'source_uri' => '@community_join', 'sort_order' => 10, 'ja' => '%community%に参加', 'en' => 'Join %Community%'],
        ['type' => 'community', 'uri' => '/community/quit', 'source_uri' => '@community_quit', 'sort_order' => 20, 'ja' => '%community%を退会', 'en' => 'Leave %Community%'],
    ];

    public function run(): void
    {
        foreach (self::ITEMS as $item) {
            $nav = Navigation::create([
                'type' => $item['type'],
                'uri' => $item['uri'],
                'source_uri' => $item['source_uri'],
                'sort_order' => $item['sort_order'],
            ]);
            $nav->setTranslation('ja_JP', $item['ja']);
            $nav->setTranslation('en', $item['en']);
        }

        app(NavigationService::class)->clearCache();
    }
}
