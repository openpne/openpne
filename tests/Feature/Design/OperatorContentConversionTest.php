<?php

declare(strict_types=1);

namespace Tests\Feature\Design;

use App\Models\Banner;
use App\Services\SnsSettingService;
use App\Support\OperatorContentConversion;
use App\Support\OperatorContentItem;
use App\Support\SnsSettingKey;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class OperatorContentConversionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('sns_settings')->delete();
        DB::table('banners')->delete();
        DB::table('gadget_configs')->delete();
        DB::table('gadgets')->delete();
    }

    public function test_writes_a_markdown_version_beside_each_piece_of_operator_html(): void
    {
        $this->seedSetting('pc_html_top', '<p><b>お知らせ</b></p>');
        $this->seedSetting('footer_after', 'Powered by <a href="http://www.openpne.jp/" target="_blank">OpenPNE</a>');
        Banner::create(['name' => 'top_before', 'is_use_html' => true, 'html' => '<a href="/shop"><img src="/b.png" alt="店"></a>']);
        $gadget = $this->seedGadget('freeArea', '<p><span style="color:#f00">赤</span></p>');

        $items = (new OperatorContentConversion)->run();

        $this->assertSame('**お知らせ**', $this->setting('insert_top_markdown'));
        $this->assertSame('Powered by [OpenPNE](http://www.openpne.jp/)', $this->setting('footer_after_markdown'));
        $this->assertSame('[![店](/b.png)](/shop)', Banner::where('name', 'top_before')->value('markdown'));
        $this->assertSame('赤', $this->gadgetConfig($gadget, 'value_markdown'));
        $this->assertCount(4, $items);
        $this->assertSame([true, true, true, true], array_map(static fn (OperatorContentItem $item): bool => $item->written, $items));
    }

    public function test_the_operator_html_itself_is_left_as_it_was(): void
    {
        $html = "<div class=\"x\">\n<script>alert(1)</script>本文</div>";
        $this->seedSetting('pc_html_bottom', $html);
        Banner::create(['name' => 'side_after', 'is_use_html' => true, 'html' => $html]);
        $gadget = $this->seedGadget('informationBox', $html);

        (new OperatorContentConversion)->run();

        $this->assertSame($html, $this->setting('pc_html_bottom'));
        $this->assertSame($html, Banner::where('name', 'side_after')->value('html'));
        $this->assertSame($html, $this->gadgetConfig($gadget, 'value'));
    }

    public function test_a_setting_with_no_stored_html_gets_no_markdown_row_and_keeps_its_default(): void
    {
        (new OperatorContentConversion)->run();

        $this->assertNull($this->setting('footer_after_markdown'));
        $this->assertSame('Powered by [OpenPNE](https://www.openpne.jp/)', app(SnsSettingService::class)->get(SnsSettingKey::FooterAfterMarkdown));
    }

    /** OpenPNE 3 read a NULL footer as empty, and the upgrade copies it as an empty row. */
    public function test_an_emptied_or_script_only_footer_stores_an_empty_markdown_instead_of_the_default(): void
    {
        $this->seedSetting('footer_before', '');
        $this->seedSetting('footer_after', '<script src="https://example.com/a.js"></script>');

        (new OperatorContentConversion)->run();

        $this->assertSame('', $this->setting('footer_before_markdown'));
        $this->assertSame('', $this->setting('footer_after_markdown'));
        $this->assertSame('', app(SnsSettingService::class)->get(SnsSettingKey::FooterAfterMarkdown));
    }

    public function test_the_head_insert_has_no_markdown_version(): void
    {
        $this->seedSetting('pc_html_head', '<meta name="x" content="y"><style>a{}</style>');

        $items = (new OperatorContentConversion)->run();

        $this->assertSame([], $items);
        $this->assertSame(1, DB::table('sns_settings')->count());
    }

    public function test_markdown_that_is_already_stored_is_kept_unless_forced(): void
    {
        $this->seedSetting('pc_html_top', '<b>新しい HTML</b>');
        $this->seedSetting('insert_top_markdown', '管理者が書き直した文');
        Banner::create(['name' => 'top_after', 'is_use_html' => true, 'html' => '<b>HTML</b>', 'markdown' => '書き直し']);
        $gadget = $this->seedGadget('freeArea', '<b>HTML</b>');
        DB::table('gadget_configs')->insert(['gadget_id' => $gadget, 'name' => 'value_markdown', 'value' => '書き直し']);

        $items = (new OperatorContentConversion)->run();

        $this->assertSame('管理者が書き直した文', $this->setting('insert_top_markdown'));
        $this->assertSame('書き直し', Banner::where('name', 'top_after')->value('markdown'));
        $this->assertSame('書き直し', $this->gadgetConfig($gadget, 'value_markdown'));
        $this->assertSame([false, false, false], array_map(static fn (OperatorContentItem $item): bool => $item->written, $items));
        $this->assertSame([false, false, false], array_map(static fn (OperatorContentItem $item): bool => $item->selected, $items));

        (new OperatorContentConversion)->run(force: true);

        $this->assertSame('**新しい HTML**', $this->setting('insert_top_markdown'));
        $this->assertSame('**HTML**', Banner::where('name', 'top_after')->value('markdown'));
        $this->assertSame('**HTML**', $this->gadgetConfig($gadget, 'value_markdown'));
        $this->assertSame(1, DB::table('gadget_configs')->where(['gadget_id' => $gadget, 'name' => 'value_markdown'])->count());
    }

    public function test_an_empty_markdown_already_stored_counts_as_stored(): void
    {
        $this->seedSetting('footer_after', '<b>x</b>');
        $this->seedSetting('footer_after_markdown', '');
        Banner::create(['name' => 'top_after', 'is_use_html' => true, 'html' => '<b>x</b>', 'markdown' => '']);

        (new OperatorContentConversion)->run();

        $this->assertSame('', $this->setting('footer_after_markdown'));
        $this->assertSame('', Banner::where('name', 'top_after')->value('markdown'));
    }

    public function test_a_dry_run_reports_the_losses_and_writes_nothing(): void
    {
        $this->seedSetting('pc_html_top', '<p style="color:red" class="a">x</p><iframe src="https://example.com/"></iframe>');

        $items = (new OperatorContentConversion)->run(dryRun: true);

        $this->assertNull($this->setting('insert_top_markdown'));
        $this->assertCount(1, $items);
        $this->assertSame('sns_settings:pc_html_top', $items[0]->location);
        $this->assertTrue($items[0]->selected);
        $this->assertFalse($items[0]->written);
        $this->assertSame(['iframe' => 1], $items[0]->converted->droppedElements);
        $this->assertSame(['class' => 1, 'style' => 1], $items[0]->converted->droppedAttributes);
    }

    public function test_only_the_named_tables_are_converted(): void
    {
        $this->seedSetting('pc_html_top', '<b>x</b>');
        Banner::create(['name' => 'top_after', 'is_use_html' => true, 'html' => '<b>x</b>']);

        (new OperatorContentConversion)->run(['banners']);

        $this->assertNull($this->setting('insert_top_markdown'));
        $this->assertSame('**x**', Banner::where('name', 'top_after')->value('markdown'));
    }

    public function test_only_information_and_free_area_gadget_bodies_are_converted(): void
    {
        $other = $this->seedGadget('linkListBox', '<b>not a body</b>');
        $free = $this->seedGadget('freeArea', '<b>body</b>');
        DB::table('gadget_configs')->insert(['gadget_id' => $free, 'name' => 'title', 'value' => '<b>題</b>']);

        (new OperatorContentConversion)->run();

        $this->assertNull($this->gadgetConfig($other, 'value_markdown'));
        $this->assertSame('**body**', $this->gadgetConfig($free, 'value_markdown'));
        $this->assertSame(1, DB::table('gadget_configs')->where('name', 'value_markdown')->count());
    }

    public function test_a_failure_part_way_rolls_every_write_back(): void
    {
        $this->seedSetting('pc_html_top', '<b>x</b>');
        // Every `[` gains a backslash, so this fits the HTML column and outgrows the Markdown one.
        Banner::create(['name' => 'top_after', 'is_use_html' => true, 'html' => '<p>'.str_repeat('[', 40000).'</p>']);

        try {
            (new OperatorContentConversion)->run();
            $this->fail('the oversized rewrite should have thrown');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('banners:top_after', $e->getMessage());
            $this->assertStringContainsString('openpne:convert-operator-html', $e->getMessage());
        }

        $this->assertNull($this->setting('insert_top_markdown'));
        $this->assertNull(Banner::where('name', 'top_after')->value('markdown'));
    }

    public function test_stored_markdown_is_kept_when_the_html_beside_it_would_outgrow_the_column(): void
    {
        $oversized = '<p>'.str_repeat('[', 40000).'</p>';
        $this->seedSetting('pc_html_top', $oversized);
        $this->seedSetting('insert_top_markdown', '書き直し');
        Banner::create(['name' => 'top_after', 'is_use_html' => true, 'html' => $oversized, 'markdown' => '書き直し']);
        $gadget = $this->seedGadget('freeArea', $oversized);
        DB::table('gadget_configs')->insert(['gadget_id' => $gadget, 'name' => 'value_markdown', 'value' => '書き直し']);

        foreach (OperatorContentConversion::TABLES as $table) {
            (new OperatorContentConversion)->run([$table]);

            try {
                (new OperatorContentConversion)->run([$table], force: true);
                $this->fail("forcing the oversized {$table} rewrite should have thrown");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('over the 65535', $e->getMessage());
            }
        }

        $this->assertSame('書き直し', $this->setting('insert_top_markdown'));
        $this->assertSame('書き直し', Banner::where('name', 'top_after')->value('markdown'));
        $this->assertSame('書き直し', $this->gadgetConfig($gadget, 'value_markdown'));
    }

    /** A write injected right after the conversion's own read stands in for another process committing between its read and its write. */
    public function test_markdown_stored_between_the_read_and_the_write_is_kept(): void
    {
        $this->seedSetting('pc_html_top', '<b>HTML</b>');
        Banner::create(['name' => 'top_after', 'is_use_html' => true, 'html' => '<b>HTML</b>']);
        $injected = [];

        DB::listen(function (QueryExecuted $query) use (&$injected): void {
            if (! str_starts_with($query->sql, 'select')) {
                return;
            }

            if (! isset($injected['setting']) && in_array('insert_top_markdown', $query->bindings, true)) {
                $injected['setting'] = true;
                $this->seedSetting('insert_top_markdown', '先に保存');
            }

            if (! isset($injected['banner']) && str_contains($query->sql, 'banners')) {
                $injected['banner'] = true;
                DB::table('banners')->where('name', 'top_after')->update(['markdown' => '先に保存']);
            }
        });

        $items = (new OperatorContentConversion)->run(['sns_settings', 'banners']);

        $this->assertSame(['setting' => true, 'banner' => true], $injected);
        $this->assertSame('先に保存', $this->setting('insert_top_markdown'));
        $this->assertSame('先に保存', Banner::where('name', 'top_after')->value('markdown'));
        $this->assertSame([false, false], array_map(static fn (OperatorContentItem $item): bool => $item->written, $items));
    }

    public function test_the_command_lists_losses_on_a_dry_run_and_writes_without_it(): void
    {
        $this->seedSetting('pc_html_top', '<p style="color:red">x</p><script>a()</script>');

        $this->artisan('openpne:convert-operator-html', ['--dry-run' => true])
            ->expectsOutputToContain('would write  sns_settings:pc_html_top')
            ->expectsOutputToContain('removed: script x1')
            ->expectsOutputToContain('attributes dropped: style x1')
            ->assertSuccessful();
        $this->assertNull($this->setting('insert_top_markdown'));

        $this->artisan('openpne:convert-operator-html')
            ->expectsOutputToContain('written  sns_settings:pc_html_top')
            ->assertSuccessful();
        $this->assertSame('x', $this->setting('insert_top_markdown'));
    }

    /** The read inside the caller's transaction stands in for another request filling the cache before the commit. */
    public function test_the_caches_are_cleared_once_the_callers_transaction_commits(): void
    {
        $this->seedSetting('pc_html_top', '<b>x</b>');
        $settings = app(SnsSettingService::class);

        DB::transaction(function () use ($settings): void {
            (new OperatorContentConversion)->run();
            $this->assertSame('**x**', $settings->get(SnsSettingKey::InsertTopMarkdown));
            $this->seedSetting('insert_top_markdown', 'later');
        });

        $this->assertSame('later', $settings->get(SnsSettingKey::InsertTopMarkdown));
    }

    public function test_a_dry_run_tells_stored_markdown_from_markdown_it_would_write(): void
    {
        $this->seedSetting('pc_html_top', '<b>x</b>');
        $this->seedSetting('insert_top_markdown', '書き直し');
        $this->seedSetting('pc_html_bottom', '<b>y</b>');
        Banner::create(['name' => 'top_after', 'is_use_html' => true, 'html' => '<b>x</b>', 'markdown' => '書き直し']);
        $gadget = $this->seedGadget('freeArea', '<b>x</b>');
        DB::table('gadget_configs')->insert(['gadget_id' => $gadget, 'name' => 'value_markdown', 'value' => '書き直し']);

        $this->artisan('openpne:convert-operator-html', ['--dry-run' => true])
            ->expectsOutputToContain('kept  sns_settings:pc_html_top')
            ->expectsOutputToContain('would write  sns_settings:pc_html_bottom')
            ->expectsOutputToContain('kept  banners:top_after')
            ->expectsOutputToContain("kept  gadgets:{$gadget} ")
            ->expectsOutputToContain('1 of 4 operator HTML values would be written')
            ->assertSuccessful();

        $this->artisan('openpne:convert-operator-html', ['--dry-run' => true, '--force' => true])
            ->expectsOutputToContain('would write  sns_settings:pc_html_top')
            ->expectsOutputToContain('would write  banners:top_after')
            ->expectsOutputToContain('4 of 4 operator HTML values would be written')
            ->assertSuccessful();

        $this->assertSame('書き直し', $this->setting('insert_top_markdown'));
        $this->assertNull($this->setting('insert_bottom_markdown'));
    }

    private function seedSetting(string $key, string $value): void
    {
        DB::table('sns_settings')->updateOrInsert(['key' => $key], ['value' => $value]);
    }

    private function setting(string $key): ?string
    {
        return DB::table('sns_settings')->where('key', $key)->value('value');
    }

    private function seedGadget(string $name, string $value): int
    {
        $id = DB::table('gadgets')->insertGetId(['context' => 'home', 'zone' => 'contents', 'name' => $name, 'sort_order' => 10]);
        DB::table('gadget_configs')->insert(['gadget_id' => $id, 'name' => 'value', 'value' => $value]);

        return $id;
    }

    private function gadgetConfig(int $gadget, string $name): ?string
    {
        return DB::table('gadget_configs')->where(['gadget_id' => $gadget, 'name' => $name])->value('value');
    }
}
