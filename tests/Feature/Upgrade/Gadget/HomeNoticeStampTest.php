<?php

declare(strict_types=1);

namespace Tests\Feature\Upgrade\Gadget;

use App\Services\SnsSettingService;
use App\Support\SnsSettingKey;
use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\Runner\HomeNoticeStamp;
use App\Upgrade\Runner\RunOptions;
use App\Upgrade\Runner\RunReport;
use App\Upgrade\Runner\UpgradeRunner;
use App\Upgrade\Steps\GadgetConfigUpgrade;
use App\Upgrade\Steps\GadgetUpgrade;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Upgrade\UpgradeSqlTestCase;

class HomeNoticeStampTest extends UpgradeSqlTestCase
{
    private const TARGETS = ['gadgets', 'gadget_configs'];

    protected function sourceTables(): array
    {
        return ['gadget', 'gadget_config'];
    }

    public function test_the_top_boxes_are_copied_in_the_order_classic_draws_them(): void
    {
        $this->box(1, 'top', null, '<p>Third</p>');
        $this->box(2, 'top', 5, '<p><strong>Second</strong></p>');
        $this->box(3, 'top', 1, '<p>First <a href="/diary">link</a></p>');

        $lines = $this->stamp();

        $this->assertSame("First [link](/diary)\n\n**Second**\n\nThird", $this->notice());
        $this->assertStringContainsString('copied to the Modern home notice', implode("\n", $lines));
        $this->assertStringNotContainsString('WARN', implode("\n", $lines));
    }

    public function test_an_empty_row_is_written_and_a_written_one_stands(): void
    {
        $this->box(1, 'top', 0, '<p>From OpenPNE 3</p>');

        DB::table('sns_settings')->insert(['key' => 'home_notice', 'value' => '']);
        $this->stamp();
        $this->assertSame('From OpenPNE 3', $this->notice());

        DB::table('sns_settings')->where('key', 'home_notice')->update(['value' => 'Written here']);
        $lines = $this->stamp();
        $this->assertSame('Written here', $this->notice());
        $this->assertStringNotContainsString('copied to the Modern home notice', implode("\n", $lines));
    }

    public function test_a_layout_without_the_top_copies_nothing_and_names_the_box(): void
    {
        DB::table('sns_settings')->insert(['key' => SnsSettingKey::GadgetHomeLayout->value, 'value' => 'layoutB']);
        $this->box(7, 'top', 0, '<p>Hidden</p>');

        $lines = implode("\n", $this->stamp());

        $this->assertDatabaseMissing('sns_settings', ['key' => 'home_notice']);
        $this->assertStringContainsString('WARN '.HomeNoticeStamp::W_NOT_COPIED.': 1 information box(es)', $lines);
        $this->assertStringContainsString('#7 top, which the home layout does not show', $lines);
    }

    public function test_the_other_home_zones_are_reported_and_the_side_banner_and_empty_boxes_are_not(): void
    {
        $this->box(1, 'top', 0, '<p>Top</p>');
        $this->box(2, 'contents', 0, '<p>Center</p>');
        $this->box(3, 'sideMenu', 0, '<p>Side</p>');
        $this->box(4, 'bottom', 0, '<script>x()</script>');
        $this->box(5, 'sideBannerContents', 0, '<p>Banner</p>');

        $lines = implode("\n", $this->stamp());

        $this->assertSame('Top', $this->notice());
        $this->assertStringContainsString('WARN '.HomeNoticeStamp::W_NOT_COPIED.': 2 information box(es)', $lines);
        $this->assertStringContainsString('#2 contents; #3 sideMenu.', $lines);
    }

    public function test_boxes_that_convert_to_nothing_write_nothing(): void
    {
        $this->box(1, 'top', 0, '<script>x()</script>');
        $this->box(2, 'top', 1, '   ');

        $lines = implode("\n", $this->stamp());

        $this->assertDatabaseMissing('sns_settings', ['key' => 'home_notice']);
        $this->assertStringNotContainsString('WARN', $lines);
    }

    public function test_a_notice_over_the_limit_is_reported_and_not_written(): void
    {
        $this->box(1, 'top', 0, '<p>'.str_repeat('a', 40000).'</p>');
        $this->box(2, 'top', 1, '<p>'.str_repeat('b', 40000).'</p>');

        $lines = implode("\n", $this->stamp());

        $this->assertDatabaseMissing('sns_settings', ['key' => 'home_notice']);
        $this->assertStringContainsString('WARN '.HomeNoticeStamp::W_TOO_LARGE.':', $lines);
    }

    public function test_a_run_without_the_gadget_steps_leaves_the_notice_alone(): void
    {
        $this->box(1, 'top', 0, '<p>Top</p>');

        $this->stamp(['gadgets']);

        $this->assertDatabaseMissing('sns_settings', ['key' => 'home_notice']);
    }

    public function test_the_runner_copies_after_the_walk_and_only_plans_on_a_dry_run(): void
    {
        $this->seedGadget(1, 'top', 'informationBox', 0);
        $this->seedConfig(1, 'value', '<p>Welcome</p>');

        $lines = [];
        $this->assertTrue($this->runner()->run(new RunOptions(dryRun: true), function (string $line) use (&$lines): void {
            $lines[] = $line;
        }));
        $this->assertStringContainsString('PLAN would copy the home\'s top information boxes', implode("\n", $lines));
        $this->assertDatabaseMissing('sns_settings', ['key' => 'home_notice']);

        $this->assertTrue($this->runner()->run(new RunOptions));
        $this->assertSame('Welcome', app(SnsSettingService::class)->get(SnsSettingKey::HomeNotice));
    }

    /**
     * @param  list<string>  $targets
     * @return list<string>
     */
    private function stamp(array $targets = self::TARGETS): array
    {
        if (DB::table('gadgets')->doesntExist()) {
            $compiler = new InsertSelectCompiler;
            DB::statement($compiler->compile(new GadgetUpgrade));
            DB::statement($compiler->compile(new GadgetConfigUpgrade));
        }

        $lines = [];
        (new HomeNoticeStamp)->run($targets, new RunReport(function (string $line) use (&$lines): void {
            $lines[] = $line;
        }));

        return $lines;
    }

    private function notice(): ?string
    {
        return DB::table('sns_settings')->where('key', 'home_notice')->value('value');
    }

    private function box(int $id, string $type, ?int $sort, string $html): void
    {
        $this->seedGadget($id, $type, 'informationBox', $sort);
        $this->seedConfig($id, 'value', $html);
    }

    private function runner(): UpgradeRunner
    {
        return new UpgradeRunner(new InsertSelectCompiler, [new GadgetUpgrade, new GadgetConfigUpgrade]);
    }

    private function seedGadget(int $id, string $type, string $name, ?int $sort): void
    {
        DB::table('gadget')->insert([
            'id' => $id,
            'type' => $type,
            'name' => $name,
            'sort_order' => $sort,
            'created_at' => '2018-03-04 12:34:56',
            'updated_at' => '2019-06-07 01:02:03',
        ]);
    }

    private function seedConfig(int $gadgetId, string $name, string $value): void
    {
        DB::table('gadget_config')->insert([
            'id' => $gadgetId,
            'gadget_id' => $gadgetId,
            'name' => $name,
            'value' => $value,
            'created_at' => '2018-03-04 12:34:56',
            'updated_at' => '2019-06-07 01:02:03',
        ]);
    }
}
