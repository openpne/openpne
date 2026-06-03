<?php

namespace Tests\Feature\Diary\Classic;

use App\Models\Diary;
use App\Models\Member;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DiaryArchiveRoutesTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/diary/listMember/1/2026/3')->assertRedirect('/login');
    }

    public function test_month_archive_filters_and_keeps_the_listmember_body_id(): void
    {
        $owner = Member::factory()->create();
        Diary::factory()->create([
            'member_id' => $owner->getKey(), 'title' => 'March entry',
            'visibility' => Visibility::Members, 'created_at' => '2026-03-10 09:00:00',
        ]);
        Diary::factory()->create([
            'member_id' => $owner->getKey(), 'title' => 'April entry',
            'visibility' => Visibility::Members, 'created_at' => '2026-04-02 09:00:00',
        ]);

        $response = $this->actingAs($owner)->get("/diary/listMember/{$owner->getKey()}/2026/3");

        $response->assertOk();
        // The archive is the same listMember action, so the OpenPNE 3 body id is unchanged.
        $response->assertSee('id="page_diary_listMember"', false);
        $response->assertSee('March entry');
        $response->assertDontSee('April entry');
        $response->assertSee('2026-03');
    }

    public function test_day_archive_filters_to_a_single_day(): void
    {
        $owner = Member::factory()->create();
        Diary::factory()->create([
            'member_id' => $owner->getKey(), 'title' => 'On the day',
            'visibility' => Visibility::Members, 'created_at' => '2026-03-15 12:00:00',
        ]);
        Diary::factory()->create([
            'member_id' => $owner->getKey(), 'title' => 'Next day',
            'visibility' => Visibility::Members, 'created_at' => '2026-03-16 12:00:00',
        ]);

        $response = $this->actingAs($owner)->get("/diary/listMember/{$owner->getKey()}/2026/3/15");

        $response->assertOk();
        $response->assertSee('On the day');
        $response->assertDontSee('Next day');
        $response->assertSee('2026-03-15');
    }

    public function test_impossible_date_returns_404(): void
    {
        $owner = Member::factory()->create();

        $this->actingAs($owner)->get("/diary/listMember/{$owner->getKey()}/2026/2/30")->assertNotFound();
    }

    public function test_out_of_range_month_does_not_match_the_route(): void
    {
        $owner = Member::factory()->create();

        $this->actingAs($owner)->get("/diary/listMember/{$owner->getKey()}/2026/13")->assertNotFound();
    }

    public function test_period_hides_a_non_friends_private_entry(): void
    {
        [$owner, $viewer] = Member::factory()->count(2)->create()->all();
        Diary::factory()->create([
            'member_id' => $owner->getKey(), 'title' => 'Secret',
            'visibility' => Visibility::Private, 'created_at' => '2026-03-10 09:00:00',
        ]);

        $this->actingAs($viewer)->get("/diary/listMember/{$owner->getKey()}/2026/3")
            ->assertOk()
            ->assertDontSee('Secret');
    }
}
