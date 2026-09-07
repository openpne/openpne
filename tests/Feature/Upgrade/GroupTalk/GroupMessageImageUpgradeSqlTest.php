<?php

namespace Tests\Feature\Upgrade\GroupTalk;

use App\Models\Group;
use App\Models\GroupMessage;
use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\Steps\GroupMessageImageUpgrade;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MigratesUpgradeTargetsOnce;
use Tests\Concerns\SeedsSourceActivities;
use Tests\Concerns\SeedsSourceMembers;
use Tests\TestCase;

/** The activity-image join step for talk-landing activities; MySQL only. */
class GroupMessageImageUpgradeSqlTest extends TestCase
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
        $group = Group::factory()->create(['id' => 5]);
        $this->seedSourceCommunity(5);
        $this->seedActivity(1, $member->id, ['foreign_table' => 'community', 'foreign_id' => 5]);
        $this->seedActivity(2, $member->id, ['in_reply_to_activity_id' => 1]);
        $this->seedActivity(3, $member->id);
        foreach ([1, 2] as $id) {
            GroupMessage::factory()->create(['id' => $id, 'group_id' => $group->id, 'member_id' => $member->id]);
        }
        foreach ([11, 12, 13, 14] as $fileId) {
            $this->seedFile($fileId);
        }
        $this->seedActivityImage(1, 1, 11);
        $this->seedActivityImage(2, 1, null, 'http://img.example/a.png'); // URL only: leaves no hole in the numbering
        $this->seedActivityImage(3, 1, 12);
        $this->seedActivityImage(4, 2, 13); // a reply's image migrates too
        $this->seedActivityImage(5, 3, 14); // a timeline thread's image lands there, not here

        DB::statement((new InsertSelectCompiler)->compile(new GroupMessageImageUpgrade));

        $this->assertSame(
            [['id' => 1, 'group_message_id' => 1, 'file_id' => 11, 'number' => 1], ['id' => 3, 'group_message_id' => 1, 'file_id' => 12, 'number' => 2], ['id' => 4, 'group_message_id' => 2, 'file_id' => 13, 'number' => 1]],
            DB::table('group_message_images')->orderBy('id')->get(['id', 'group_message_id', 'file_id', 'number'])->map(fn ($r) => (array) $r)->all(),
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
