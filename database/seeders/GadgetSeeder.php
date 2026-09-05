<?php

namespace Database\Seeders;

use App\Models\Gadget;
use App\Services\GadgetService;
use Illuminate\Database\Seeder;

/**
 * Only the PC types of OpenPNE 3's default gadget set are seeded; the mobile and smartphone ones
 * are not.
 */
class GadgetSeeder extends Seeder
{
    /** @var list<array{context: string, zone: string, name: string, sort_order: int}> */
    private const ITEMS = [
        ['context' => 'home', 'zone' => 'top', 'name' => 'birthdayBox', 'sort_order' => 0],
        ['context' => 'home', 'zone' => 'top', 'name' => 'informationBox', 'sort_order' => 20],
        ['context' => 'home', 'zone' => 'contents', 'name' => 'diaryFriendList', 'sort_order' => 101],
        ['context' => 'home', 'zone' => 'contents', 'name' => 'diaryList', 'sort_order' => 102],
        ['context' => 'home', 'zone' => 'contents', 'name' => 'diaryCommentHistory', 'sort_order' => 103],
        ['context' => 'home', 'zone' => 'contents', 'name' => 'diaryMyList', 'sort_order' => 104],
        ['context' => 'home', 'zone' => 'contents', 'name' => 'timelineAll', 'sort_order' => 120],
        ['context' => 'home', 'zone' => 'contents', 'name' => 'recentGroupTopicComment', 'sort_order' => 131],
        ['context' => 'home', 'zone' => 'contents', 'name' => 'recentGroupEventComment', 'sort_order' => 132],
        ['context' => 'home', 'zone' => 'sideMenu', 'name' => 'memberImageBox', 'sort_order' => 10],
        ['context' => 'home', 'zone' => 'sideMenu', 'name' => 'friendListBox', 'sort_order' => 20],
        ['context' => 'home', 'zone' => 'sideMenu', 'name' => 'groupJoinListBox', 'sort_order' => 30],

        ['context' => 'profile', 'zone' => 'top', 'name' => 'birthdayBox', 'sort_order' => 0],
        ['context' => 'profile', 'zone' => 'contents', 'name' => 'timelineProfile', 'sort_order' => 20],
        ['context' => 'profile', 'zone' => 'contents', 'name' => 'diaryMemberList', 'sort_order' => 101],
        ['context' => 'profile', 'zone' => 'sideMenu', 'name' => 'memberImageBox', 'sort_order' => 10],
        ['context' => 'profile', 'zone' => 'sideMenu', 'name' => 'profileListBox', 'sort_order' => 15],
        ['context' => 'profile', 'zone' => 'sideMenu', 'name' => 'friendListBox', 'sort_order' => 20],
        ['context' => 'profile', 'zone' => 'sideMenu', 'name' => 'groupJoinListBox', 'sort_order' => 30],

        ['context' => 'login', 'zone' => 'contents', 'name' => 'loginForm', 'sort_order' => 10],

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
