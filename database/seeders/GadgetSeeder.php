<?php

namespace Database\Seeders;

use App\Models\Gadget;
use App\Services\GadgetService;
use Illuminate\Database\Seeder;

/**
 * OpenPNE 3's default PC gadget set (data/fixtures/005_import_gadgets.yml), with the OpenPNE 3
 * `type` split into OpenPNE 4 (context, zone). Mobile / smartphone types are dropped (those
 * frontends are out of scope). Config is left to each kind's defaults, as the fixture does. Runs on
 * db:seed, not migrate, so an existing install has no gadgets until seeded (pre-release).
 */
class GadgetSeeder extends Seeder
{
    /** @var list<array{context: string, zone: string, name: string, sort_order: int}> */
    private const ITEMS = [
        // home
        ['context' => 'home', 'zone' => 'top', 'name' => 'informationBox', 'sort_order' => 20],
        ['context' => 'home', 'zone' => 'sideMenu', 'name' => 'memberImageBox', 'sort_order' => 10],
        ['context' => 'home', 'zone' => 'sideMenu', 'name' => 'friendListBox', 'sort_order' => 20],
        ['context' => 'home', 'zone' => 'sideMenu', 'name' => 'communityJoinListBox', 'sort_order' => 30],

        // profile
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
