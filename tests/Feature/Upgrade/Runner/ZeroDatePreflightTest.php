<?php

namespace Tests\Feature\Upgrade\Runner;

use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\Runner\ZeroDatePreflight;
use App\Upgrade\SourceSchema;
use App\Upgrade\Steps\DiaryUpgrade;
use App\Upgrade\Steps\DirectMessageUpgrade;
use App\Upgrade\Steps\GroupEventUpgrade;
use App\Upgrade\Steps\GroupJoinRequestUpgrade;
use App\Upgrade\Steps\GroupMemberUpgrade;
use App\Upgrade\Steps\MemberProfileUpgrade;
use App\Upgrade\Steps\MemberUpgrade;
use App\Upgrade\Steps\ProfileUpgrade;
use App\Upgrade\UpgradeStep;
use Closure;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SeedsSourceMembers;
use Tests\Feature\Upgrade\UpgradeSqlTestCase;

/**
 * The scan evaluates each step's own SELECT, so a zero date counts exactly when the INSERT would
 * carry it: under the step's filter, through its CASE arms and its subqueries.
 */
class ZeroDatePreflightTest extends UpgradeSqlTestCase
{
    use SeedsSourceMembers;

    private const ZERO = '0000-00-00 00:00:00';

    protected function sourceTables(): array
    {
        return ['member', 'member_config', 'community_member', 'community_member_position', 'community_event', 'message', 'message_send_list', 'deleted_message', 'message_type', 'profile', 'profile_option', 'member_profile'];
    }

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('DROP TABLE IF EXISTS `op_member`');
            DB::statement('DROP TABLE IF EXISTS `op_member_config`');
        }

        parent::tearDown();
    }

    public function test_a_zero_date_on_a_copied_row_is_an_error_naming_the_source_column(): void
    {
        $member = $this->activeMember();
        $this->withoutStrictMode(fn () => DB::table('member')->where('id', $member->id)->update(['created_at' => self::ZERO]));

        $this->assertSame(
            [ZeroDatePreflight::zeroDateMessage('MemberUpgrade', 'members', 'created_at', 'member', ['created_at'], false, 1, [$member->id], false)],
            $this->inspect([new MemberUpgrade]),
        );
    }

    public function test_rows_are_counted_per_column_with_the_first_ids(): void
    {
        [$a, $b, $c, $d, $e, $f, $g] = $this->activeMembers(7);
        $this->withoutStrictMode(function () use ($a, $b, $c, $d, $e, $f, $g): void {
            foreach ([$a, $b, $c, $d, $e, $f] as $member) {
                DB::table('member')->where('id', $member->id)->update(['created_at' => self::ZERO]);
            }
            DB::table('member')->where('id', $g->id)->update(['updated_at' => '2010-00-00 00:00:00']);
        });

        $errors = $this->inspect([new MemberUpgrade]);

        $this->assertCount(2, $errors);
        $this->assertStringContainsString('on 6 row(s) MemberUpgrade copies into `members`.`created_at` (e.g. id '.implode(', ', [$a->id, $b->id, $c->id, $d->id, $e->id]).')', $errors[0]);
        $this->assertStringContainsString("on 1 row(s) MemberUpgrade copies into `members`.`updated_at` (e.g. id {$g->id})", $errors[1]);
        $this->assertStringNotContainsString('NULL', $errors[0]);
    }

    public function test_a_zero_date_on_a_row_the_step_does_not_copy_is_not_an_error(): void
    {
        $inactive = $this->inactiveSourceMember(900);
        $this->withoutStrictMode(fn () => DB::table('member')->where('id', $inactive)->update(['created_at' => self::ZERO]));

        $this->assertSame([], $this->inspect([new MemberUpgrade]));
    }

    public function test_a_column_only_the_sibling_step_copies_is_scoped_to_that_step(): void
    {
        $member = $this->activeMember();
        // GroupMemberUpgrade copies updated_at under is_pre = 0; GroupJoinRequestUpgrade (is_pre = 1) does not copy it.
        $this->seedCommunityMember(1, $member->id, isPre: 1);
        $this->seedCommunityMember(2, $member->id, isPre: 0);
        $this->withoutStrictMode(fn () => DB::table('community_member')->where('id', 1)->update(['updated_at' => self::ZERO]));

        $this->assertSame([], $this->inspect([new GroupMemberUpgrade, new GroupJoinRequestUpgrade]));

        $this->withoutStrictMode(fn () => DB::table('community_member')->where('id', 2)->update(['updated_at' => self::ZERO]));

        $this->assertSame(
            [ZeroDatePreflight::zeroDateMessage('GroupMemberUpgrade', 'group_members', 'updated_at', 'community_member', ['updated_at'], false, 1, [2], false)],
            $this->inspect([new GroupMemberUpgrade, new GroupJoinRequestUpgrade]),
        );
    }

    public function test_a_nullable_source_column_offers_null(): void
    {
        $this->seedEvent(5);
        $this->withoutStrictMode(fn () => DB::table('community_event')->where('id', 5)->update(['application_deadline' => self::ZERO]));

        $errors = $this->inspect([new GroupEventUpgrade]);

        $this->assertSame([ZeroDatePreflight::zeroDateMessage('GroupEventUpgrade', 'group_events', 'application_deadline', 'community_event', ['application_deadline'], false, 1, [5], true)], $errors);
        $this->assertStringContainsString('(or NULL)', $errors[0]);
    }

    public function test_a_subquery_value_counts_only_where_the_step_reads_it(): void
    {
        $sender = $this->activeMember();
        DB::table('message_type')->insert(['id' => 1, 'type_name' => 'message', 'foreign_table' => null, 'is_deleted' => 0, 'created_at' => '2016-01-01 00:00:00', 'updated_at' => '2016-01-01 00:00:00']);
        $this->seedMessage(600, $sender->id, isDeleted: 1);
        $this->seedMessage(601, $sender->id, isDeleted: 0);
        // The sender's trash timestamp is the latest deleted_message pointer's created_at, read only when is_deleted = 1.
        $this->seedPointer(1, $sender->id, 600, self::ZERO);
        $this->seedPointer(2, $sender->id, 600, '2020-01-01 00:00:00');
        $this->seedPointer(3, $sender->id, 601, self::ZERO);

        $this->assertSame([], $this->inspect([new DirectMessageUpgrade]));

        DB::table('deleted_message')->where('id', 2)->delete();

        // The CASE turns the subquery's zero into NULL with a 1292 warning, which the INSERT turns into an
        // error; the rows holding the value are then located (pointer 3 holds it too, unread).
        $this->assertSame(
            [ZeroDatePreflight::zeroDateReadMessage('DirectMessageUpgrade', 'deleted_message', 'created_at', 2, [1, 3], false)],
            $this->inspect([new DirectMessageUpgrade]),
        );

        // A zero the SELECT carries itself does not hide the one it reads by subquery.
        $this->withoutStrictMode(fn () => DB::table('message')->where('id', 600)->update(['created_at' => self::ZERO]));

        $this->assertSame([
            ZeroDatePreflight::zeroDateMessage('DirectMessageUpgrade', 'direct_messages', 'created_at', 'message', ['created_at'], false, 1, [600], false),
            ZeroDatePreflight::zeroDateReadMessage('DirectMessageUpgrade', 'deleted_message', 'created_at', 2, [1, 3], false),
        ], $this->inspect([new DirectMessageUpgrade]));
    }

    public function test_a_sentinel_the_step_maps_to_null_is_not_an_error_but_a_zero_month_is(): void
    {
        $member = $this->activeMember();
        DB::table('profile')->insert(['id' => 10, 'name' => 'op_preset_birthday', 'form_type' => 'date', 'value_type' => 'string', 'default_public_flag' => 1, 'created_at' => '2018-01-01 00:00:00', 'updated_at' => '2018-01-01 00:00:00']);
        $this->seedMemberProfile(1000, $member->id, 10);
        $this->withoutStrictMode(fn () => DB::table('member_profile')->where('id', 1000)->update(['value_datetime' => self::ZERO]));

        $this->assertSame([], $this->inspect([new MemberProfileUpgrade]));
        DB::statement((new InsertSelectCompiler)->compile(new ProfileUpgrade));
        DB::statement((new InsertSelectCompiler)->compile(new MemberProfileUpgrade));
        $this->assertNull(DB::table('member_profiles')->where('id', 1000)->value('value_datetime'));

        DB::table('member_profiles')->delete();
        $this->withoutStrictMode(fn () => DB::table('member_profile')->where('id', 1000)->update(['value_datetime' => '2010-00-00 00:00:00']));

        $this->assertSame(
            [ZeroDatePreflight::zeroDateMessage('MemberProfileUpgrade', 'member_profiles', 'value_datetime', 'member_profile', ['value_datetime'], true, 1, [1000], true)],
            $this->inspect([new MemberProfileUpgrade]),
        );
    }

    public function test_a_valid_date_with_a_zero_year_is_not_an_error(): void
    {
        $member = $this->activeMember();
        // OpenPNE 3's own sentinel, and a year-0000 date strict mode accepts (NO_ZERO_IN_DATE is about month and day).
        DB::table('member')->where('id', $member->id)->update(['created_at' => '0001-01-01 00:00:00', 'updated_at' => '0000-01-01 00:00:00']);

        $this->assertSame([], $this->inspect([new MemberUpgrade]));
    }

    public function test_an_absent_optional_table_is_stood_in_without_being_created(): void
    {
        $member = $this->activeMember();
        $this->seedMessage(600, $member->id, isDeleted: 0);
        DB::table('message_type')->insert(['id' => 1, 'type_name' => 'message', 'foreign_table' => null, 'is_deleted' => 0, 'created_at' => '2016-01-01 00:00:00', 'updated_at' => '2016-01-01 00:00:00']);
        $this->withoutStrictMode(fn () => DB::table('message')->where('id', 600)->update(['created_at' => self::ZERO]));
        DB::statement('DROP TABLE `deleted_message`');

        // deleted_message is read twice in one SELECT; the stand-in CTE takes both, and the FROM table's zero still counts.
        $this->assertSame(
            [ZeroDatePreflight::zeroDateMessage('DirectMessageUpgrade', 'direct_messages', 'created_at', 'message', ['created_at'], false, 1, [600], false)],
            (new ZeroDatePreflight([new DirectMessageUpgrade, new DiaryUpgrade], SourceSchema::default()))->inspect('', null, ['deleted_message', 'diary']),
        );
        $this->assertNull(DB::selectOne('select 1 from information_schema.tables where table_schema = ? and table_name in (?, ?)', [DB::connection()->getDatabaseName(), 'diary', 'deleted_message']));
    }

    public function test_the_source_prefix_resolves(): void
    {
        foreach (['member', 'member_config'] as $table) {
            DB::statement(preg_replace('/^CREATE TABLE `'.$table.'`/', "CREATE TABLE `op_{$table}`", SourceSchema::default()->createStatement($table, withoutForeignKeys: true), 1));
        }
        DB::table('op_member')->insert(['id' => 7, 'name' => 'Seven', 'is_login_rejected' => 0, 'is_active' => 1, 'created_at' => '2018-03-04 12:34:56', 'updated_at' => '2019-06-07 01:02:03']);
        $this->withoutStrictMode(fn () => DB::table('op_member')->where('id', 7)->update(['updated_at' => self::ZERO]));

        $this->assertSame(
            [ZeroDatePreflight::zeroDateMessage('MemberUpgrade', 'members', 'updated_at', 'member', ['updated_at'], false, 1, [7], false)],
            (new ZeroDatePreflight([new MemberUpgrade], SourceSchema::default()))->inspect('op_', null, []),
        );
    }

    /**
     * @param  list<UpgradeStep>  $steps
     * @return list<string>
     */
    private function inspect(array $steps): array
    {
        return (new ZeroDatePreflight($steps, SourceSchema::default()))->inspect('', null, []);
    }

    private function withoutStrictMode(Closure $seed): void
    {
        $mode = DB::selectOne('SELECT @@SESSION.sql_mode AS mode')->mode;
        DB::statement("SET SESSION sql_mode = ''");
        try {
            $seed();
        } finally {
            DB::statement("SET SESSION sql_mode = '{$mode}'");
        }
    }

    private function seedCommunityMember(int $id, int $memberId, int $isPre): void
    {
        DB::table('community_member')->insert(['id' => $id, 'community_id' => 1, 'member_id' => $memberId, 'is_pre' => $isPre, 'is_receive_mail_pc' => 0, 'is_receive_mail_mobile' => 0, 'created_at' => '2018-01-01 00:00:00', 'updated_at' => '2018-01-01 00:00:00']);
    }

    private function seedEvent(int $id): void
    {
        DB::table('community_event')->insert(['id' => $id, 'community_id' => 1, 'member_id' => null, 'name' => 'Event', 'body' => 'Body', 'event_updated_at' => '2019-06-07 01:02:03', 'open_date' => '2019-07-01 00:00:00', 'open_date_comment' => '', 'area' => '', 'application_deadline' => '2019-06-30 00:00:00', 'capacity' => 20, 'created_at' => '2018-03-04 12:34:56', 'updated_at' => '2019-06-07 01:02:03']);
    }

    private function seedMessage(int $id, int $memberId, int $isDeleted): void
    {
        DB::table('message')->insert(['id' => $id, 'member_id' => $memberId, 'subject' => 'S', 'body' => 'B', 'is_deleted' => $isDeleted, 'is_send' => 1, 'thread_message_id' => 0, 'return_message_id' => 0, 'message_type_id' => 1, 'foreign_id' => 0, 'created_at' => '2018-01-01 00:00:00', 'updated_at' => '2018-01-01 00:00:00']);
    }

    private function seedPointer(int $id, int $memberId, int $messageId, string $createdAt): void
    {
        $this->withoutStrictMode(fn () => DB::table('deleted_message')->insert(['id' => $id, 'member_id' => $memberId, 'message_id' => $messageId, 'message_send_list_id' => 0, 'is_deleted' => 0, 'created_at' => $createdAt, 'updated_at' => '2020-01-01 00:00:00']));
    }

    private function seedMemberProfile(int $id, int $memberId, int $profileId): void
    {
        DB::table('member_profile')->insert(['id' => $id, 'member_id' => $memberId, 'profile_id' => $profileId, 'profile_option_id' => null, 'value' => '', 'value_datetime' => null, 'public_flag' => 1, 'tree_key' => $id, 'lft' => 1, 'rgt' => null, 'level' => null, 'created_at' => '2019-01-01 00:00:00', 'updated_at' => '2019-01-01 00:00:00']);
    }
}
