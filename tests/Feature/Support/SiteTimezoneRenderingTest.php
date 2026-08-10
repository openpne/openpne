<?php

namespace Tests\Feature\Support;

use App\Models\Diary;
use App\Models\Member;
use App\Support\LocalizedDate;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Tests\TestCase;

/**
 * Both surfaces have to name the same wall-clock time. Classic formats server-side in the site
 * timezone; Modern formats client-side from an offset-bearing ISO, which it can only place on the
 * same clock if the site timezone reaches it — hence the shared prop asserted here.
 */
class SiteTimezoneRenderingTest extends TestCase
{
    use RefreshDatabase;

    private string $originalTimezone;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalTimezone = date_default_timezone_get();
        config(['openpne.surface_mode' => 'modern_default']);
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->originalTimezone);
        parent::tearDown();
    }

    public function test_classic_renders_an_instant_in_the_site_timezone(): void
    {
        $this->useSiteTimezone('Asia/Tokyo');
        App::setLocale('ja');

        // 15:05Z is the next day in Tokyo: the offset has to be applied, not the UTC wall clock read off.
        $stored = CarbonImmutable::parse('2026-08-09T15:05:16+00:00')->setTimezone(config('app.timezone'));

        $this->assertSame('2026年08月10日 00:05', LocalizedDate::dateTime($stored));
    }

    public function test_the_site_timezone_reaches_the_client(): void
    {
        $this->useSiteTimezone('Asia/Tokyo');

        $this->actingAs(Member::factory()->create(), 'member')
            ->get('/notifications')
            ->assertInertia(fn ($page) => $page->where('timezone', 'Asia/Tokyo'));
    }

    public function test_a_serialized_instant_carries_its_offset_so_the_client_can_place_it(): void
    {
        $this->useSiteTimezone('Asia/Tokyo');
        $author = Member::factory()->create();
        $diary = Diary::factory()->for($author, 'member')->create(['created_at' => '2026-08-10 00:05:16']);

        $this->actingAs($author, 'member')
            ->get("/diary/{$diary->getKey()}")
            ->assertInertia(fn ($page) => $page->where('diary.createdAt', '2026-08-10T00:05:16+09:00'));
    }

    /**
     * Config alone is not the site timezone: LoadConfiguration also hands the value to
     * date_default_timezone_set, and that is what the Eloquent date casts read. Setting only the
     * config leaves the two disagreeing — a state production never boots into.
     */
    private function useSiteTimezone(string $timezone): void
    {
        config(['app.timezone' => $timezone]);
        date_default_timezone_set($timezone);
    }
}
