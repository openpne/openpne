<?php

namespace Tests\Feature\Diary\Modern;

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
        $this->get('/m/diary/listMember/1/2026/3')->assertRedirect('/login');
    }

    public function test_month_archive_renders_inertia_with_period_and_filtered_data(): void
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

        $this->actingAs($owner)->get("/m/diary/listMember/{$owner->getKey()}/2026/3")
            ->assertInertia(fn ($page) => $page
                ->component('diary/list')
                ->where('period', '2026-03')
                ->has('diaries.data', 1)
                ->where('diaries.data.0.title', 'March entry')
            );
    }

    public function test_impossible_date_returns_404(): void
    {
        $owner = Member::factory()->create();

        $this->actingAs($owner)->get("/m/diary/listMember/{$owner->getKey()}/2026/2/30")->assertNotFound();
    }
}
