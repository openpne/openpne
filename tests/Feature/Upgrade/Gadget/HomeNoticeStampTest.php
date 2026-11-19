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
use PHPUnit\Framework\Attributes\DataProvider;
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

        app(SnsSettingService::class)->get(SnsSettingKey::HomeNotice);
        $lines = $this->stamp();

        $this->assertSame("First [link](/diary)\n\n**Second**\n\nThird", $this->notice());
        $this->assertSame($this->notice(), app(SnsSettingService::class)->get(SnsSettingKey::HomeNotice));
        $this->assertStringContainsString('copied to the Modern home notice', implode("\n", $lines));
        $this->assertStringNotContainsString('WARN', implode("\n", $lines));
    }

    public function test_a_notice_past_the_short_setting_limit_is_copied_whole(): void
    {
        $this->box(1, 'top', 0, '<p>'.str_repeat('あ', 100).'</p>');

        $this->stamp();

        $this->assertSame(str_repeat('あ', 100), $this->notice());
    }

    #[DataProvider('blankRows')]
    public function test_a_blank_row_is_written_and_a_written_one_stands(string $blank): void
    {
        $this->box(1, 'top', 0, '<p>From OpenPNE 3</p>');

        DB::table('sns_settings')->insert(['key' => 'home_notice', 'value' => $blank]);
        $this->stamp();
        $this->assertSame('From OpenPNE 3', $this->notice());

        DB::table('sns_settings')->where('key', 'home_notice')->update(['value' => 'Written here']);
        $lines = $this->stamp();
        $this->assertSame('Written here', $this->notice());
        $this->assertStringNotContainsString('copied to the Modern home notice', implode("\n", $lines));
    }

    /** @return array<string, array{string}> */
    public static function blankRows(): array
    {
        return ['empty' => [''], 'whitespace' => ["  \n"]];
    }

    /** The settings cache is warmed on the default layout first, so a stamp reading it stale would copy. */
    #[DataProvider('layoutsWithoutTheTop')]
    public function test_a_layout_without_the_top_copies_nothing_and_names_the_box(string $layout, string $hiddenSide): void
    {
        app(SnsSettingService::class)->get(SnsSettingKey::GadgetHomeLayout);
        DB::table('sns_settings')->insert(['key' => SnsSettingKey::GadgetHomeLayout->value, 'value' => $layout]);
        $this->box(7, 'top', 0, '<p>Hidden</p>');
        $this->box(8, 'sideMenu', 0, '<p>Side</p>');

        $lines = implode("\n", $this->stamp());

        $this->assertDatabaseMissing('sns_settings', ['key' => 'home_notice']);
        $this->assertStringContainsString('WARN '.HomeNoticeStamp::W_NOT_COPIED.': 2 information box(es)', $lines);
        $this->assertStringContainsString('#7 top, which the home layout does not show; #8 sideMenu'.$hiddenSide.'.', $lines);
    }

    /** @return array<string, array{string, string}> */
    public static function layoutsWithoutTheTop(): array
    {
        return [
            'B' => ['layoutB', ''],
            'C' => ['layoutC', ', which the home layout does not show'],
        ];
    }

    public function test_a_stored_layout_a_copies_the_top(): void
    {
        DB::table('sns_settings')->insert(['key' => SnsSettingKey::GadgetHomeLayout->value, 'value' => 'layoutA']);
        $this->box(1, 'top', 0, '<p>Top</p>');

        $this->stamp();

        $this->assertSame('Top', $this->notice());
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

    public function test_a_written_notice_stands_without_a_size_warning(): void
    {
        $this->box(1, 'top', 0, '<p>'.str_repeat('a', 40000).'</p>');
        $this->box(2, 'top', 1, '<p>'.str_repeat('b', 40000).'</p>');
        DB::table('sns_settings')->insert(['key' => 'home_notice', 'value' => 'Written here']);

        $lines = implode("\n", $this->stamp());

        $this->assertSame('Written here', $this->notice());
        $this->assertStringNotContainsString('WARN', $lines);
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

    public function test_a_run_that_fails_after_the_gadgets_copies_nothing(): void
    {
        $this->seedGadget(1, 'top', 'informationBox', 0);
        $this->seedConfig(1, 'value', '<p>Welcome</p>');

        $runner = new UpgradeRunner(new InsertSelectCompiler, [new GadgetUpgrade, new GadgetConfigUpgrade, new BrokenGadgetUpgrade]);

        $this->assertFalse($runner->run(new RunOptions, function (): void {}));
        $this->assertSame(1, DB::table('gadgets')->count());
        $this->assertDatabaseMissing('sns_settings', ['key' => 'home_notice']);
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

final class BrokenGadgetUpgrade extends GadgetUpgrade
{
    public function targetTable(): string
    {
        return 'no_such_table';
    }
}
