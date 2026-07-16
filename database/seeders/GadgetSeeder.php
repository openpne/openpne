<?php

namespace Database\Seeders;

use App\Models\Gadget;
use App\Services\GadgetService;
use Illuminate\Database\Seeder;

/**
 * The default PC gadget set. Mobile / smartphone types are dropped (those frontends are out of
 * scope). Config is left to each kind's defaults. Runs on db:seed, not migrate, so an existing
 * install has no gadgets until seeded (pre-release).
 */
class GadgetSeeder extends Seeder
{
    /** @var list<array{context: string, zone: string, name: string, sort_order: int}> */
    private const ITEMS = [
        // home
        ['context' => 'home', 'zone' => 'top', 'name' => 'informationBox', 'sort_order' => 20],
        ['context' => 'home', 'zone' => 'contents', 'name' => 'diaryFriendList', 'sort_order' => 101],
        ['context' => 'home', 'zone' => 'contents', 'name' => 'diaryList', 'sort_order' => 102],
        ['context' => 'home', 'zone' => 'contents', 'name' => 'diaryCommentHistory', 'sort_order' => 103],
        ['context' => 'home', 'zone' => 'contents', 'name' => 'diaryMyList', 'sort_order' => 104],
        ['context' => 'home', 'zone' => 'sideMenu', 'name' => 'memberImageBox', 'sort_order' => 10],
        ['context' => 'home', 'zone' => 'sideMenu', 'name' => 'friendListBox', 'sort_order' => 20],
        ['context' => 'home', 'zone' => 'sideMenu', 'name' => 'communityJoinListBox', 'sort_order' => 30],

        // profile
        ['context' => 'profile', 'zone' => 'contents', 'name' => 'diaryMemberList', 'sort_order' => 101],
        ['context' => 'profile', 'zone' => 'sideMenu', 'name' => 'memberImageBox', 'sort_order' => 10],
        ['context' => 'profile', 'zone' => 'sideMenu', 'name' => 'profileListBox', 'sort_order' => 15],
        ['context' => 'profile', 'zone' => 'sideMenu', 'name' => 'friendListBox', 'sort_order' => 20],
        ['context' => 'profile', 'zone' => 'sideMenu', 'name' => 'communityJoinListBox', 'sort_order' => 30],

        // login
        ['context' => 'login', 'zone' => 'contents', 'name' => 'loginForm', 'sort_order' => 10],

        // sidebanner (global)
        ['context' => 'sidebanner', 'zone' => 'contents', 'name' => 'languageSelecterBox', 'sort_order' => 10],
    ];

    public function run(): void
    {
        foreach (self::ITEMS as $item) {
            Gadget::create($item);
        }

        app(GadgetService::class)->clearCache();
    }
}
