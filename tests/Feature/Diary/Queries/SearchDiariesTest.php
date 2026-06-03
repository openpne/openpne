<?php

namespace Tests\Feature\Diary\Queries;

use App\Features\Diary\Queries\SearchDiaries;
use App\Models\Diary;
use App\Models\Member;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SearchDiariesTest extends TestCase
{
    use RefreshDatabase;

    public function test_matches_keyword_in_title_or_body(): void
    {
        $viewer = Member::factory()->create();
        $this->diary('Laravel tips', 'about the framework');
        $this->diary('Cooking', 'I love laravel pasta');
        $this->diary('Unrelated', 'nothing here');

        $result = (new SearchDiaries)($viewer, 'laravel');

        // Case-insensitive LIKE matches the title of one and the body of the other.
        $this->assertSame(2, $result->total());
    }

    public function test_multiple_terms_are_and_connected(): void
    {
        $viewer = Member::factory()->create();
        $this->diary('Laravel and React', 'full stack');
        $this->diary('Laravel only', 'backend');

        $result = (new SearchDiaries)($viewer, 'laravel react');

        $this->assertSame(1, $result->total());
    }

    public function test_full_width_space_separates_terms(): void
    {
        $viewer = Member::factory()->create();
        $this->diary('Laravel React', 'x');
        $this->diary('Laravel', 'y');

        $result = (new SearchDiaries)($viewer, 'laravel　react');

        $this->assertSame(1, $result->total());
    }

    public function test_empty_keyword_returns_all_member_visible_diaries(): void
    {
        $viewer = Member::factory()->create();
        $this->diary('A', 'a');
        $this->diary('B', 'b');

        $this->assertSame(2, (new SearchDiaries)($viewer, '   ')->total());
    }

    public function test_only_searches_the_all_member_tier(): void
    {
        $viewer = Member::factory()->create();
        $owner = Member::factory()->create();
        Diary::factory()->create([
            'member_id' => $owner->getKey(), 'title' => 'secret laravel',
            'visibility' => Visibility::Friends,
        ]);
        Diary::factory()->create([
            'member_id' => $owner->getKey(), 'title' => 'public laravel',
            'visibility' => Visibility::Members,
        ]);

        $result = (new SearchDiaries)($viewer, 'laravel');

        // Friend-only/private diaries are out of the searchable tier, matching OpenPNE 3.
        $this->assertSame(1, $result->total());
    }

    public function test_excludes_a_blocking_owners_diary(): void
    {
        $viewer = Member::factory()->create();
        $blocker = Member::factory()->create();
        Diary::factory()->create([
            'member_id' => $blocker->getKey(), 'title' => 'blocked laravel',
            'visibility' => Visibility::Members,
        ]);
        DB::table('member_blocks')->insert([
            'blocker_id' => $blocker->getKey(), 'blocked_id' => $viewer->getKey(),
        ]);

        $this->assertSame(0, (new SearchDiaries)($viewer, 'laravel')->total());
    }

    public function test_results_are_paginated(): void
    {
        $viewer = Member::factory()->create();
        Diary::factory()->count(25)->create([
            'title' => 'laravel', 'visibility' => Visibility::Members,
        ]);

        $result = (new SearchDiaries)($viewer, 'laravel', perPage: 20);

        $this->assertSame(20, $result->perPage());
        $this->assertSame(25, $result->total());
    }

    private function diary(string $title, string $body): Diary
    {
        return Diary::factory()->create([
            'title' => $title, 'body' => $body, 'visibility' => Visibility::Members,
        ]);
    }
}
