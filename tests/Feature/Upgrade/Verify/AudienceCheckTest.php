<?php

namespace Tests\Feature\Upgrade\Verify;

use App\Support\Visibility;
use App\Upgrade\Column;
use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\Runner\RunOptions;
use App\Upgrade\Steps\DiaryUpgrade;
use App\Upgrade\Steps\FileUpgrade;
use App\Upgrade\Steps\GroupUpgrade;
use App\Upgrade\Steps\MemberPreferenceUpgrade;
use App\Upgrade\Steps\MemberProfileUpgrade;
use App\Upgrade\Steps\MemberUpgrade;
use App\Upgrade\Steps\ProfileUpgrade;
use App\Upgrade\Steps\TimelinePostUpgrade;
use App\Upgrade\Steps\TimelineReplyUpgrade;
use App\Upgrade\UpgradeStep;
use App\Upgrade\Verify\AudienceCheck;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SeedsSourceActivities;
use Tests\Concerns\SeedsSourceMembers;
use Tests\Feature\Upgrade\UpgradeSqlTestCase;

class AudienceCheckTest extends UpgradeSqlTestCase
{
    use SeedsSourceActivities, SeedsSourceMembers;

    private const STAMP = '2015-01-01 00:00:00';

    protected function sourceTables(): array
    {
        $tables = ['member'];
        foreach ($this->steps() as $step) {
            $tables = [...$tables, ...$step->readSourceTables()];
        }

        return array_values(array_unique($tables));
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedSourceMember(1, isActive: 1);
        $this->seedSourceMember(2, isActive: 1);
        $this->seedSourceMember(9, isActive: 0);
        $this->config(1, 'profile_page_public_flag', '4');
        $this->config(1, 'diary_public_flag', '2');
        $this->config(2, 'age_public_flag', '3');
        $this->config(9, 'age_public_flag', '1'); // an inactive member's row the guard leaves behind

        DB::table('profile')->insert(['id' => 1, 'name' => 'hobby', 'default_public_flag' => 2, 'is_public_web' => 1, 'form_type' => 'input', 'created_at' => self::STAMP, 'updated_at' => self::STAMP]);
        DB::table('profile')->insert(['id' => 2, 'name' => 'motto', 'default_public_flag' => 0, 'form_type' => 'input', 'created_at' => self::STAMP, 'updated_at' => self::STAMP]);
        $this->memberProfile(1, 1, 1, null);
        $this->memberProfile(2, 2, 1, 4);

        $this->diary(1, 1, 1, 0);
        $this->diary(2, 1, 1, 1);
        $this->diary(3, 2, 2, 1);
        $this->diary(4, 2, 4, 0);

        $this->seedSourceCommunity(1);
        $this->groupConfig(1, 'public_flag', 'auth_commu_member');
        $this->seedSourceCommunity(2);
        $this->seedActivity(1, 1, ['public_flag' => 2]);
        $this->seedActivity(2, 2, ['in_reply_to_activity_id' => 1, 'public_flag' => 0]);
        $this->seedActivity(3, 2, ['public_flag' => 3]);
        $this->seedActivity(4, 1, ['public_flag' => 2, 'foreign_table' => 'community', 'foreign_id' => 1]); // a group thread the timeline does not take

        DB::table('file')->insert(['id' => 1, 'name' => 'admin_logo_1', 'type' => 'image/png', 'created_at' => self::STAMP, 'updated_at' => self::STAMP]);
        DB::table('file')->insert(['id' => 2, 'name' => 'm_1_1', 'type' => 'image/png', 'created_at' => self::STAMP, 'updated_at' => self::STAMP]);
    }

    public function test_a_clean_upgrade_passes_and_reports_each_distribution(): void
    {
        $out = $this->upgradeAndCheck();

        $this->assertStringNotContainsString('FAIL', $out);
        $this->assertStringContainsString('PASS audience:DiaryUpgrade.visibility: public_flag=1 is_open=0 → 1: 1, public_flag=1 is_open=1 → 0: 1, public_flag=2 is_open=1 → 2: 1, public_flag=4 is_open=0 → 0: 1', $out);
        $this->assertStringContainsString('PASS audience:TimelinePostUpgrade.visibility: public_flag=2 → 2: 1, public_flag=3 → 3: 1', $out);
        $this->assertStringContainsString('PASS audience:TimelineReplyUpgrade.visibility: root public_flag=2 → 2: 1', $out);
        $this->assertStringContainsString('PASS audience:MemberUpgrade.profile_visibility: profile_page_public_flag=NULL → 1: 1, profile_page_public_flag=4 → 0: 1', $out);
        $this->assertStringContainsString('PASS audience:MemberPreferenceUpgrade.value: key=age_visibility value=3 → 3: 1, key=diary_default_visibility value=2 → 2: 1', $out);
        $this->assertStringContainsString('PASS audience:ProfileUpgrade.default_visibility: default_public_flag=0 → 1: 1, default_public_flag=2 → 2: 1', $out);
        $this->assertStringContainsString('PASS audience:ProfileUpgrade.is_public_web: is_public_web=0 → 0: 1, is_public_web=1 → 1: 1', $out);
        $this->assertStringContainsString('PASS audience:MemberProfileUpgrade.visibility: public_flag=NULL → NULL: 1, public_flag=4 → 0: 1', $out);
        $this->assertStringContainsString('PASS audience:FileUpgrade.explicit_visibility: admin image=0 → NULL: 1, admin image=1 → public: 1', $out);
        $this->assertStringContainsString('PASS audience:GroupUpgrade.topic_read_access: public_flag=NULL → 1: 1, public_flag=auth_commu_member → 2: 1', $out);
    }

    public function test_a_target_row_whose_audience_changed_fails_with_both_counts(): void
    {
        $this->upgrade();
        DB::table('diaries')->where('id', 3)->update(['visibility' => Visibility::Open->value]);
        DB::table('member_preferences')->where('value', '2')->delete();
        DB::table('member_preferences')->where('key', 'age_visibility')->update(['key' => 'diary_default_visibility', 'member_id' => 1]);

        $out = $this->check();

        $this->assertStringContainsString('FAIL audience:DiaryUpgrade.visibility: diaries visibility=0: source 2, target 3; diaries visibility=2: source 1, target 0', $out);
        $this->assertStringContainsString('FAIL audience:MemberPreferenceUpgrade.value: member_preferences key=age_visibility value=3: source 1, target 0; member_preferences key=diary_default_visibility value=2: source 1, target 0; member_preferences key=diary_default_visibility value=3: source 0, target 1', $out);
    }

    public function test_a_source_value_the_step_does_not_expect_fails_though_its_rows_were_copied(): void
    {
        DB::table('member_config')->where('name', 'profile_page_public_flag')->update(['value' => '2']);
        // Each variant comes after the row it would fold into under a _ci collation.
        $this->config(1, 'age_public_flag', '3 ');
        $this->groupConfig(2, 'public_flag', 'Auth_Commu_Member');
        DB::table('member_profile')->where('id', 1)->update(['public_flag' => 9]);

        $out = $this->upgradeAndCheck();

        $this->assertStringContainsString('FAIL audience:MemberUpgrade.profile_visibility: member values the step does not expect: profile_page_public_flag=2 (1 rows → 1)', $out);
        $this->assertStringContainsString('FAIL audience:MemberPreferenceUpgrade.value: member_config values the step does not expect: key=age_visibility value="3 " (1 rows → 3)', $out);
        $this->assertStringContainsString('FAIL audience:GroupUpgrade.topic_read_access: community values the step does not expect: public_flag=Auth_Commu_Member (1 rows → 2)', $out);
        $this->assertStringContainsString('FAIL audience:MemberProfileUpgrade.visibility: member_profile values the step does not expect: public_flag=9 (1 rows → NULL)', $out);
        $this->assertSame(4, substr_count($out, 'FAIL'), $out);
    }

    public function test_the_mapping_is_the_step_s_own_so_one_that_differs_from_the_run_fails(): void
    {
        $this->upgrade();

        $friendsAsMembers = new class extends DiaryUpgrade
        {
            public function columns(): array
            {
                return ['visibility' => Column::expr('CASE WHEN public_flag = 2 THEN 1 ELSE 0 END', uses: ['public_flag'])] + parent::columns();
            }
        };

        $this->assertStringContainsString(': diaries visibility=0: source 3, target 2; diaries visibility=2: source 0, target 1', $this->check([$friendsAsMembers]));
    }

    /** @return list<UpgradeStep> in target-FK order */
    private function steps(): array
    {
        return [new MemberUpgrade, new ProfileUpgrade, new MemberProfileUpgrade, new MemberPreferenceUpgrade, new DiaryUpgrade,
            new TimelinePostUpgrade, new TimelineReplyUpgrade, new FileUpgrade, new GroupUpgrade];
    }

    private function upgrade(): void
    {
        $compiler = new InsertSelectCompiler;
        foreach ($this->steps() as $step) {
            DB::statement($compiler->compile($step));
        }
    }

    private function upgradeAndCheck(): string
    {
        $this->upgrade();

        return $this->check();
    }

    /** @param  list<UpgradeStep>|null  $steps */
    private function check(?array $steps = null): string
    {
        $lines = [];
        (new AudienceCheck(new InsertSelectCompiler))->verify($steps ?? $this->steps(), new RunOptions, [], function (string $name, bool $pass, string $detail) use (&$lines): void {
            $lines[] = ($pass ? 'PASS' : 'FAIL')." {$name}: {$detail}";
        });

        return implode("\n", $lines);
    }

    private function config(int $memberId, string $name, string $value): void
    {
        DB::table('member_config')->insert(['member_id' => $memberId, 'name' => $name, 'value' => $value,
            'name_value_hash' => md5("{$name},{$value}"), 'created_at' => self::STAMP, 'updated_at' => self::STAMP]);
    }

    private function groupConfig(int $groupId, string $name, string $value): void
    {
        DB::table('community_config')->insert(['community_id' => $groupId, 'name' => $name, 'value' => $value, 'created_at' => self::STAMP, 'updated_at' => self::STAMP]);
    }

    private function memberProfile(int $id, int $memberId, int $profileId, ?int $publicFlag): void
    {
        DB::table('member_profile')->insert(['id' => $id, 'member_id' => $memberId, 'profile_id' => $profileId, 'value' => 'v',
            'public_flag' => $publicFlag, 'tree_key' => $id, 'lft' => 1, 'rgt' => 2, 'level' => 0, 'created_at' => self::STAMP, 'updated_at' => self::STAMP]);
    }

    private function diary(int $id, int $memberId, int $publicFlag, int $isOpen): void
    {
        DB::table('diary')->insert(['id' => $id, 'member_id' => $memberId, 'title' => 't', 'body' => 'b',
            'public_flag' => $publicFlag, 'is_open' => $isOpen, 'created_at' => self::STAMP, 'updated_at' => self::STAMP]);
    }
}
