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

    #[DataProvider('surfaces')]
    public function test_the_viewer_owner_pair_is_asked_about_once(string $surface): void
    {
        config(['openpne.surface_mode' => $surface]);
        $viewer = Member::factory()->create();
        $owner = Member::factory()->create();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($viewer)->get(route('member.profile.show', $owner))->assertOk();
        $statements = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        // The one-direction block read and the friendship read, as the memo answers them; the
        // either-direction block read behind the friend form is a single query and stays.
        $pairReads = array_filter($statements, fn (string $sql): bool => preg_match(
            '/^select exists\(select \* from (["`])member_blocks\1 where \1blocker_id\1 = \? and \1blocked_id\1 = \?\)|^select exists\(.*["`]friendships["`]/',
            $sql,
        ) === 1);
        $this->assertSame([], array_values($pairReads), 'single-pair block or friend reads on the profile page');
    }
}
