<?php

namespace Tests\Feature\Upgrade\Timeline;

use App\Models\TimelinePost;
use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\Steps\TimelinePostImageUpgrade;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MigratesUpgradeTargetsOnce;
use Tests\Concerns\SeedsSourceActivities;
use Tests\Concerns\SeedsSourceMembers;
use Tests\TestCase;

/** The activity-image join step for timeline-landing activities; MySQL only. */
class TimelinePostImageUpgradeSqlTest extends TestCase
{
    use MigratesUpgradeTargetsOnce, SeedsSourceActivities, SeedsSourceMembers;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Upgrade INSERT...SELECT runs on MySQL (source DDL + set-based copy).');
        }

        $this->createSourceMemberTable();
        $this->createSourceActivityTables();
    }

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            $this->dropSourceActivityTables();
            $this->dropSourceMemberTable();
        }

        parent::tearDown();
    }

    public function test_numbers_the_file_backed_images_and_skips_the_url_only_and_the_other_landing(): void
    {
        $member = $this->activeMember();
        $this->seedSourceCommunity(5);
        $this->seedActivity(1, $member->id);
        $this->seedActivity(2, $member->id, ['in_reply_to_activity_id' => 1]);
        $this->seedActivity(3, $member->id, ['foreign_table' => 'community', 'foreign_id' => 5]);
        foreach ([1, 2, 3] as $id) {
            TimelinePost::factory()->create(['id' => $id, 'member_id' => $member->id]);
        }
        foreach ([11, 12, 13, 14] as $fileId) {
            $this->seedFile($fileId);
        }
        $this->seedActivityImage(1, 1, 11);
        $this->seedActivityImage(2, 1, null, 'http://img.example/a.png'); // URL only: leaves no hole in the numbering
        $this->seedActivityImage(3, 1, 12);
        $this->seedActivityImage(4, 2, 13); // a reply's image migrates too
        $this->seedActivityImage(5, 3, 14); // a community thread's image lands in talk, not here

        DB::statement((new InsertSelectCompiler)->compile(new TimelinePostImageUpgrade));

        $this->assertSame(
            [['id' => 1, 'timeline_post_id' => 1, 'file_id' => 11, 'number' => 1], ['id' => 3, 'timeline_post_id' => 1, 'file_id' => 12, 'number' => 2], ['id' => 4, 'timeline_post_id' => 2, 'file_id' => 13, 'number' => 1]],
            DB::table('timeline_post_images')->orderBy('id')->get(['id', 'timeline_post_id', 'file_id', 'number'])->map(fn ($r) => (array) $r)->all(),
        );
    }

    private function seedFile(int $id): void
    {
        DB::table('files')->insert([
            'id' => $id,
            'name' => "tok_{$id}",
            'type' => 'image/png',
            'byte_size' => 128,
            'created_at' => '2016-01-01 00:00:00',
            'updated_at' => '2016-01-01 00:00:00',
        ]);
    }
}
