<?php

namespace Tests\Feature\Profile;

use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProfileShowQueryBudgetTest extends TestCase
{
    use RefreshDatabase;

    /** @return iterable<string, array{string}> */
    public static function surfaces(): iterable
    {
        yield 'classic' => ['classic_default'];
        yield 'modern' => ['modern_default'];
    }

    /** A stranger and a friend, so the memo answers both ways; a blocked viewer never reaches the page. */
    #[DataProvider('surfaces')]
    public function test_the_viewer_owner_pair_is_asked_about_once(string $surface): void
    {
        config(['openpne.surface_mode' => $surface]);
        $viewer = Member::factory()->create();
        $stranger = Member::factory()->create();
        $friend = Member::factory()->create();
        $viewer->friendships()->attach($friend->getKey());
        $friend->friendships()->attach($viewer->getKey());

        foreach ([$stranger, $friend] as $owner) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($viewer)->get(route('member.profile.show', $owner))->assertOk();
            $statements = array_column(DB::getQueryLog(), 'query');
            DB::disableQueryLog();

            // Any single-pair probe, from either block fallback or the friendship one; the
            // either-direction block read behind the friend form is one query and stays.
            $pairReads = array_filter($statements, fn (string $sql): bool => str_starts_with($sql, 'select exists(')
                && preg_match('/["`](member_blocks|friendships)["`]/', $sql) === 1
                && ! str_contains($sql, ' or ('));
            $this->assertSame([], array_values($pairReads), 'single-pair block or friend reads on the profile page');
        }
    }
}
