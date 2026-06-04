<?php

namespace Tests\Feature\Diary\Classic;

use App\Models\Diary;
use App\Models\Member;
use App\Support\Visibility;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Locks OpenPNE 3's diary LayoutB sidemenu: a screen that opts into it renders the two-column
 * layout (id="LayoutB" + id="Left") with the author box and recent-diaries list, while a diary
 * screen that does not opt in keeps the single-column LayoutC. Anchors are markup hooks/routes.
 */
class DiarySidemenuParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_renders_the_layoutb_left_sidemenu_with_author_and_recent_diaries(): void
    {
        $owner = Member::factory()->create();
        $recent = Diary::factory()->create(['member_id' => $owner->getKey(), 'title' => 'Sidemenu Recent Entry']);
        $current = Diary::factory()->create(['member_id' => $owner->getKey(), 'title' => 'Current Entry']);

        $response = $this->actingAs($owner)->get("/diary/{$current->getKey()}");

        $response->assertOk();
        $response->assertSee('id="LayoutB"', false);                                    // two-column layout
        $response->assertSee('id="Left"', false);                                       // sidemenu slot
        $response->assertSee('class="parts memberImageBox"', false);                    // OpenPNE 3 hook
        $response->assertSee('href="'.route('member.profile.show', $owner).'"', false); // author profile link
        $response->assertSee('Recently Posted');                                        // recent box heading
        $response->assertSee('href="'.route('diary.show', $recent).'"', false);         // recent entry link
        $response->assertSee('Sidemenu Recent Entry (0)');                              // title + comment count
    }

    public function test_listmember_opts_into_the_sidemenu(): void
    {
        $owner = Member::factory()->create();
        Diary::factory()->create(['member_id' => $owner->getKey()]);

        $this->actingAs($owner)->get('/diary/listMember')
            ->assertOk()
            ->assertSee('id="LayoutB"', false)
            ->assertSee('id="Left"', false);
    }

    public function test_recent_box_is_scoped_to_the_viewer(): void
    {
        [$owner, $other] = Member::factory()->count(2)->create()->all();
        Diary::factory()->create(['member_id' => $owner->getKey(), 'visibility' => Visibility::Members, 'title' => 'Members Visible']);
        Diary::factory()->private()->create(['member_id' => $owner->getKey(), 'title' => 'Private Hidden']);

        $this->actingAs($other)->get("/diary/listMember/{$owner->getKey()}")
            ->assertOk()
            ->assertSee('Members Visible')
            ->assertDontSee('Private Hidden'); // not in the sidemenu recent box for a non-friend
    }

    public function test_all_member_feed_without_a_sidemenu_stays_single_column(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)->get('/diary/list')
            ->assertOk()
            ->assertSee('id="LayoutC"', false)
            ->assertDontSee('id="Left"', false);
    }

    public function test_show_calendar_focuses_the_diarys_month_and_links_days_with_entries(): void
    {
        $owner = Member::factory()->create();
        $diary = Diary::factory()->create(['member_id' => $owner->getKey(), 'created_at' => '2026-03-14 10:00:00']);

        $response = $this->actingAs($owner)->get("/diary/{$diary->getKey()}");

        $response->assertOk();
        $response->assertSee('class="calendar"', false);                                            // OpenPNE 3 hook
        $response->assertSee('2026-03');                                                             // focused month
        $dayArchive = route('diary.list_member.archive', ['member' => $owner, 'year' => 2026, 'month' => 3, 'day' => 14]);
        $response->assertSee('>14</a>', false);                                                      // the 14th is linked
        $response->assertSee($dayArchive, false);
        $response->assertSee(route('diary.list_member.archive', ['member' => $owner, 'year' => 2026, 'month' => 2]), false); // prev month
        $response->assertSee(route('diary.list_member.archive', ['member' => $owner, 'year' => 2026, 'month' => 4]), false); // next month
    }

    public function test_calendar_day_without_a_diary_is_not_linked(): void
    {
        $owner = Member::factory()->create();
        $diary = Diary::factory()->create(['member_id' => $owner->getKey(), 'created_at' => '2026-03-14 10:00:00']);

        // The 1st has no diary, so no day-archive link (the closing quote pins the exact day).
        $this->actingAs($owner)->get("/diary/{$diary->getKey()}")
            ->assertOk()
            ->assertDontSee(route('diary.list_member.archive', ['member' => $owner, 'year' => 2026, 'month' => 3, 'day' => 1]).'"', false);
    }

    public function test_plain_listmember_calendar_focuses_the_current_month(): void
    {
        $this->travelTo(CarbonImmutable::create(2026, 6, 15));
        $owner = Member::factory()->create();

        $this->actingAs($owner)->get('/diary/listMember')
            ->assertOk()
            ->assertSee('class="calendar"', false)
            ->assertSee('2026-06');
    }
}
