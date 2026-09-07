<?php

namespace Tests\Feature\Upgrade\Runner;

use App\Upgrade\Runner\FileOwnerPreflight;
use App\Upgrade\SourceSchema;
use App\Upgrade\Steps\FileUpgrade;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MigratesUpgradeTargetsOnce;
use Tests\Concerns\SeedsSourceActivities;
use Tests\Concerns\SeedsSourceMembers;
use Tests\TestCase;

/** One owner per file, counted across every owning table FileUpgrade knows; MySQL only. */
class FileOwnerPreflightTest extends TestCase
{
    use MigratesUpgradeTargetsOnce, SeedsSourceActivities, SeedsSourceMembers;

    private const OWNER_TABLES = ['file', 'member_image', 'diary_image', 'diary_comment_image', 'community_topic_image',
        'community_topic_comment_image', 'community_event_image', 'community_event_comment_image', 'message_file',
        'message', 'message_type', 'banner_image'];

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('The preflight counts over the OpenPNE 3 source DDL on MySQL.');
        }

        $this->createSourceMemberTable();
        $this->createSourceActivityTables();
        foreach (self::OWNER_TABLES as $table) {
            DB::statement("DROP TABLE IF EXISTS `{$table}`");
            DB::statement(SourceSchema::default()->createStatement($table, withoutForeignKeys: true));
        }
    }

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            foreach (array_reverse(self::OWNER_TABLES) as $table) {
                DB::statement("DROP TABLE IF EXISTS `{$table}`");
            }
            $this->dropSourceActivityTables();
            $this->dropSourceMemberTable();
        }

        parent::tearDown();
    }

    public function test_a_file_referenced_from_two_places_is_an_error_and_single_references_are_not(): void
    {
        $member = $this->activeMember();
        $this->seedSourceCommunity(5);
        $this->seedActivity(1, $member->id);
        $this->seedActivity(2, $member->id, ['foreign_table' => 'community', 'foreign_id' => 5]);
        // 40: an activity image and a diary image; 41: a timeline thread's and a talk thread's image;
        // 42 and 43: one reference each.
        $this->seedActivityImage(1, 1, 40);
        DB::table('diary_image')->insert(['id' => 1, 'diary_id' => 9, 'file_id' => 40, 'number' => 1]);
        $this->seedActivityImage(2, 1, 41);
        $this->seedActivityImage(3, 2, 41);
        $this->seedActivityImage(4, 2, 42);
        DB::table('diary_image')->insert(['id' => 2, 'diary_id' => 9, 'file_id' => 43, 'number' => 2]);

        $this->assertSame(FileOwnerPreflight::sharedFileOwnerMessage(2, [40, 41]), (new FileOwnerPreflight)->inspect('', null, (new FileUpgrade)->readSourceTables()));

        DB::table('diary_image')->where('id', 1)->delete();
        DB::table('activity_image')->where('id', 3)->delete();
        $this->assertNull((new FileOwnerPreflight)->inspect('', null, (new FileUpgrade)->readSourceTables()));
    }

    public function test_an_absent_optional_table_is_left_out_of_the_count(): void
    {
        DB::statement('DROP TABLE `diary_image`');

        $this->assertNull((new FileOwnerPreflight)->inspect('', null, array_values(array_diff((new FileUpgrade)->readSourceTables(), ['diary_image']))));
    }
}
