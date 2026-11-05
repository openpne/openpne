<?php

namespace Tests\Feature\Upgrade\Term;

use App\Services\TermService;
use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\Steps\WithdrawnMemberTermEnUpgrade;
use App\Upgrade\Steps\WithdrawnMemberTermJaUpgrade;
use App\Upgrade\Steps\WithdrawnMemberTermUpgrade;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Upgrade\UpgradeSqlTestCase;

/** Runs the compiled sns_config → term_overrides copy of the withdrawn-member nickname. */
class WithdrawnMemberTermUpgradeSqlTest extends UpgradeSqlTestCase
{
    protected function sourceTables(): array
    {
        return ['sns_config'];
    }

    public function test_a_site_value_is_carried_into_every_locale(): void
    {
        $this->seedConfig('卒業生');

        $this->runUpgrade();

        $this->assertSame(
            ['en' => '卒業生', 'ja' => '卒業生'],
            DB::table('term_overrides')->where('name', 'withdrawn_member')->orderBy('locale')->pluck('value', 'locale')->all(),
        );

        $service = app(TermService::class);
        $service->clearCache();
        app()->setLocale('ja');
        $this->assertSame('卒業生', __('%Withdrawn_member%'));
        $this->assertSame('退会したメンバー', TermService::defaults('ja')['withdrawn_member'], 'the default itself is untouched');
    }

    /** OpenPNE 3 read a NULL row as its default, like no row; an empty one the admin page could not keep. */
    public function test_the_source_default_a_null_an_empty_value_and_no_row_write_nothing(): void
    {
        foreach ([WithdrawnMemberTermUpgrade::SOURCE_DEFAULT, null, ''] as $value) {
            DB::table('sns_config')->delete();
            $this->seedConfig($value);

            $this->runUpgrade();

            $this->assertSame(0, DB::table('term_overrides')->count(), var_export($value, true));
        }

        DB::table('sns_config')->delete();
        $this->runUpgrade();
        $this->assertSame(0, DB::table('term_overrides')->count());
    }

    /** The column's collation equates these with `-`; a site that typed them keeps them. */
    public function test_a_dash_the_collation_equates_with_the_default_is_a_site_value(): void
    {
        foreach (['－', '- '] as $value) {
            DB::table('sns_config')->delete();
            DB::table('term_overrides')->delete();
            $this->seedConfig($value);

            $this->runUpgrade();

            $this->assertSame(
                ['en' => $value, 'ja' => $value],
                DB::table('term_overrides')->orderBy('locale')->pluck('value', 'locale')->all(),
            );
        }
    }

    public function test_another_setting_is_not_read(): void
    {
        DB::table('sns_config')->insert(['name' => 'sns_name', 'value' => 'My SNS']);

        $this->runUpgrade();

        $this->assertSame(0, DB::table('term_overrides')->count());
    }

    private function runUpgrade(): void
    {
        foreach ([new WithdrawnMemberTermJaUpgrade, new WithdrawnMemberTermEnUpgrade] as $step) {
            DB::statement((new InsertSelectCompiler)->compile($step));
        }
    }

    private function seedConfig(?string $value): void
    {
        DB::table('sns_config')->insert(['name' => WithdrawnMemberTermUpgrade::SOURCE_NAME, 'value' => $value]);
    }
}
