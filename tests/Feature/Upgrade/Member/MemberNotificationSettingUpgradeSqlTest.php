<?php

namespace Tests\Feature\Upgrade\Member;

use App\Models\Member;
use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\SourceSchema;
use App\Upgrade\Steps\MemberNotificationSettingUpgrade;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MigratesUpgradeTargetsOnce;
use Tests\Concerns\SeedsSourceMembers;
use Tests\TestCase;

/**
 * Runs the compiled member_config → member_notification_settings step against the real OpenPNE 3 DDL;
 * MySQL only, with a target member created first so the member_id FK resolves.
 */
class MemberNotificationSettingUpgradeSqlTest extends TestCase
{
    use MigratesUpgradeTargetsOnce, SeedsSourceMembers;

    private int $memberId;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Upgrade INSERT...SELECT runs on MySQL.');
        }

        $this->createSourceMemberTable();

        DB::statement('DROP TABLE IF EXISTS `member_config`');
        DB::statement(SourceSchema::default()->createStatement('member_config', withoutForeignKeys: true));

        $this->memberId = $this->activeMember()->getKey();
    }

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            $this->dropSourceMemberTable();
            DB::statement('DROP TABLE IF EXISTS `member_config`');
        }

        parent::tearDown();
    }

    public function test_maps_both_channels_of_a_kind_to_their_rows(): void
    {
        $this->seedConfig(1, $this->memberId, 'is_send_messageNew_web', '0');
        $this->seedConfig(2, $this->memberId, 'is_send_pc_messageNew_mail', '1');

        $this->runUpgrade();

        $this->assertDatabaseHas('member_notification_settings', [
            'member_id' => $this->memberId, 'kind' => 'direct_message_new', 'channel' => 'web', 'is_enabled' => 0,
        ]);
        $this->assertDatabaseHas('member_notification_settings', [
            'member_id' => $this->memberId, 'kind' => 'direct_message_new', 'channel' => 'mail', 'is_enabled' => 1,
        ]);
    }

    public function test_zero_is_the_only_opt_out(): void
    {
        // The source form wrote '0'/'1', but the fail-open read treated anything but '0' as on.
        $this->seedConfig(1, $this->memberId, 'is_send_friendLinkConfirm_web', '0');
        $this->seedConfig(2, $this->memberId, 'is_send_pc_friendLinkConfirm_mail', 'garbage');

        $this->runUpgrade();

        $this->assertDatabaseHas('member_notification_settings', [
            'kind' => 'friend_link_confirm', 'channel' => 'web', 'is_enabled' => 0,
        ]);
        $this->assertDatabaseHas('member_notification_settings', [
            'kind' => 'friend_link_confirm', 'channel' => 'mail', 'is_enabled' => 1,
        ]);
    }

    public function test_unwired_kinds_import_too(): void
    {
        // One-shot upgrade: an unwired kind's opt-out must survive regardless of whether a sender exists.
        $this->seedConfig(1, $this->memberId, 'is_send_pc_timelineNewPost_mail', '0');

        $this->runUpgrade();

        $this->assertDatabaseHas('member_notification_settings', [
            'kind' => 'timeline_new_post', 'channel' => 'mail', 'is_enabled' => 0,
        ]);
    }

    public function test_latest_row_wins_for_a_duplicated_name(): void
    {
        $this->seedConfig(10, $this->memberId, 'is_send_diaryReplyPost_web', '1'); // older
        $this->seedConfig(20, $this->memberId, 'is_send_diaryReplyPost_web', '0'); // newer

        $this->runUpgrade();

        $this->assertSame(1, DB::table('member_notification_settings')->count());
        $this->assertDatabaseHas('member_notification_settings', [
            'kind' => 'diary_reply_post', 'channel' => 'web', 'is_enabled' => 0,
        ]);
    }

    public function test_unregistered_names_are_not_migrated(): void
    {
        // Unknown is_send_ shapes (custom keys) and non-catalog names stay behind.
        $this->seedConfig(1, $this->memberId, 'is_send_customThing_web', '0');
        $this->seedConfig(2, $this->memberId, 'is_send_pc_birthday_mail', '0'); // digest, not a catalog kind
        $this->seedConfig(3, $this->memberId, 'language', 'ja_JP');

        $this->runUpgrade();

        $this->assertSame(0, DB::table('member_notification_settings')->count());
    }

    private function runUpgrade(): void
    {
        DB::statement((new InsertSelectCompiler)->compile(new MemberNotificationSettingUpgrade));
    }

    private function seedConfig(int $id, int $memberId, string $name, string $value): void
    {
        DB::table('member_config')->insert([
            'id' => $id,
            'member_id' => $memberId,
            'name' => $name,
            'value' => $value,
            'name_value_hash' => md5($name.$value),
            'created_at' => '2018-03-04 12:34:56',
            'updated_at' => '2019-06-07 01:02:03',
        ]);
    }
}
