<?php

declare(strict_types=1);

namespace Tests\Feature\Upgrade\SnsSetting;

use App\Models\Banner;
use App\Models\UpgradeState;
use App\Support\SnsSettingKey;
use App\Upgrade\Runner\OperatorMarkdownTransform;
use App\Upgrade\Runner\RunReport;
use App\Upgrade\Steps\SnsSettingUpgrade;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class OperatorMarkdownTransformTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $out = [];

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('sns_settings')->delete();
        DB::table('banners')->delete();
    }

    public function test_writes_the_markdown_and_completes_its_checkpoint(): void
    {
        $this->seedSetting('footer_after', '<p>運営: <a href="/about" target="_blank">事務局</a></p>');
        Banner::create(['name' => 'top_before', 'is_use_html' => true, 'html' => '<b>広告</b><script>a()</script>']);

        $this->assertTrue($this->runPass(['sns_settings', 'banners', 'gadget_configs']));

        $this->assertSame('運営: [事務局](/about)', $this->stored('footer_after_markdown'));
        $this->assertSame('**広告**', Banner::where('name', 'top_before')->value('markdown'));
        $state = UpgradeState::where('step_key', 'operator_markdown')->firstOrFail();
        $this->assertSame(UpgradeState::STATUS_COMPLETED, $state->status);
        $this->assertSame(2, (int) $state->rows_affected);
        $this->assertStringContainsString('2 operator HTML values hold markup Markdown cannot express', implode("\n", $this->out));
    }

    public function test_says_nothing_about_losses_when_there_are_none(): void
    {
        $this->seedSetting('footer_after', '<p>運営事務局</p>');

        $this->assertTrue($this->runPass(['sns_settings']));

        $this->assertStringNotContainsString('cannot express', implode("\n", $this->out));
    }

    public function test_converts_only_the_tables_the_run_owns_and_skips_a_run_that_owns_none(): void
    {
        $this->seedSetting('footer_after', '<b>x</b>');
        Banner::create(['name' => 'top_before', 'is_use_html' => true, 'html' => '<b>x</b>']);

        $this->assertTrue($this->runPass(['members']));
        $this->assertSame(0, UpgradeState::where('step_key', 'operator_markdown')->count());
        $this->assertSame([], (new OperatorMarkdownTransform)->checkpointKeys(['members'], []));

        $this->assertTrue($this->runPass(['banners']));
        $this->assertNull($this->stored('footer_after_markdown'));
        $this->assertSame('**x**', Banner::where('name', 'top_before')->value('markdown'));
        $this->assertSame(['operator_markdown'], (new OperatorMarkdownTransform)->checkpointKeys(['banners'], []));
    }

    public function test_a_completed_pass_does_not_run_again(): void
    {
        $this->seedSetting('footer_after', '<b>x</b>');
        $this->assertTrue($this->runPass(['sns_settings']));

        $this->seedSetting('footer_before', '<b>later</b>');
        $this->assertTrue($this->runPass(['sns_settings']));

        $this->assertNull($this->stored('footer_before_markdown'));
        $this->assertStringContainsString('already completed', implode("\n", $this->out));
    }

    public function test_a_failure_rolls_the_writes_back_and_the_retry_completes(): void
    {
        $this->seedSetting('footer_after', '<b>x</b>');
        Banner::create(['name' => 'top_before', 'is_use_html' => true, 'html' => '<p>'.str_repeat('[', 40000).'</p>']);

        $this->assertFalse($this->runPass(['sns_settings', 'banners']));

        $this->assertNull($this->stored('footer_after_markdown'));
        $state = UpgradeState::where('step_key', 'operator_markdown')->firstOrFail();
        $this->assertSame(UpgradeState::STATUS_FAILED, $state->status);
        $this->assertStringContainsString('then run the upgrade again', (string) $state->error);

        Banner::where('name', 'top_before')->update(['html' => '<b>short</b>']);
        $this->assertTrue($this->runPass(['sns_settings', 'banners']));

        $this->assertSame('**x**', $this->stored('footer_after_markdown'));
        $this->assertSame('**short**', Banner::where('name', 'top_before')->value('markdown'));
        $this->assertSame(UpgradeState::STATUS_COMPLETED, UpgradeState::where('step_key', 'operator_markdown')->value('status'));
    }

    /** A failing output closure after the commit must not flip the checkpoint, as with the policy pass. */
    public function test_a_failing_progress_line_after_the_commit_leaves_the_checkpoint_completed(): void
    {
        $this->seedSetting('footer_after', '<b>x</b>');

        try {
            (new OperatorMarkdownTransform)->run(['sns_settings'], new RunReport(function (string $line): void {
                if (str_contains($line, 'DONE')) {
                    throw new RuntimeException('output closed');
                }
            }));
        } catch (RuntimeException) {
        }

        $this->assertSame(UpgradeState::STATUS_COMPLETED, UpgradeState::where('step_key', 'operator_markdown')->value('status'));
        $this->assertSame('**x**', $this->stored('footer_after_markdown'));
    }

    public function test_the_settings_step_neither_copies_nor_counts_the_markdown_keys(): void
    {
        $filter = (string) (new SnsSettingUpgrade)->targetFilter();

        foreach (SnsSettingKey::cases() as $key) {
            if ($key->isOperatorMarkdown()) {
                $this->assertNull($key->op3SourceName(), $key->value);
                $this->assertStringNotContainsString("'{$key->value}'", $filter, $key->value);
            }
        }

        $this->assertStringContainsString("'footer_after'", $filter);
    }

    /** @param list<string> $tables */
    private function runPass(array $tables): bool
    {
        return (new OperatorMarkdownTransform)->run($tables, new RunReport(function (string $line): void {
            $this->out[] = $line;
        }));
    }

    private function seedSetting(string $key, string $value): void
    {
        DB::table('sns_settings')->updateOrInsert(['key' => $key], ['value' => $value]);
    }

    private function stored(string $key): ?string
    {
        return DB::table('sns_settings')->where('key', $key)->value('value');
    }
}
