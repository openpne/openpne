<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\GadgetService;
use App\Services\SnsSettingService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Writes the Markdown that stands in for each stored piece of operator HTML, leaving the HTML as it
 * is (docs/internals/upgrade.md, "Operator HTML"). A missing HTML row writes nothing,
 * and an empty or script-only one writes an empty Markdown, so the Markdown default never replaces
 * a value an operator had set.
 */
final class OperatorContentConversion
{
    public const TABLES = ['sns_settings', 'banners', 'gadget_configs'];

    public const GADGET_HTML = 'value';

    public const GADGET_MARKDOWN = 'value_markdown';

    private const GADGETS = ['informationBox', 'freeArea'];

    /** The TEXT columns the Markdown is written to. */
    private const MAX_BYTES = 65535;

    /**
     * @param  list<string>  $tables  the subset of TABLES to convert
     * @param  bool  $force  rewrite Markdown that is already stored
     * @param  bool  $dryRun  convert and report without writing
     * @return list<OperatorContentItem>
     */
    public function run(array $tables = self::TABLES, bool $force = false, bool $dryRun = false): array
    {
        $items = DB::transaction(fn (): array => [
            ...(in_array('sns_settings', $tables, true) ? $this->settings($force, $dryRun) : []),
            ...(in_array('banners', $tables, true) ? $this->banners($force, $dryRun) : []),
            ...(in_array('gadget_configs', $tables, true) ? $this->gadgets($force, $dryRun) : []),
        ]);

        if (! $dryRun) {
            app(SnsSettingService::class)->clearCache();
            app(GadgetService::class)->clearCache();
        }

        return $items;
    }

    /** @return list<OperatorContentItem> */
    private function settings(bool $force, bool $dryRun): array
    {
        $items = [];

        foreach (SnsSettingKey::cases() as $html) {
            $markdown = $html->markdownCounterpart();
            // Read as rows, not through SnsSettingService: its default would hide whether one is stored.
            $source = $markdown === null ? null : DB::table('sns_settings')->where('key', $html->value)->value('value');

            if ($markdown === null || ! is_string($source)) {
                continue;
            }

            $converted = $this->convert("sns_settings:{$html->value}", $source);
            $write = ! $dryRun && ($force || ! DB::table('sns_settings')->where('key', $markdown->value)->exists());

            if ($write) {
                DB::table('sns_settings')->updateOrInsert(['key' => $markdown->value], ['value' => $converted->markdown]);
            }

            $items[] = new OperatorContentItem("sns_settings:{$html->value}", $converted, $write);
        }

        return $items;
    }

    /** @return list<OperatorContentItem> */
    private function banners(bool $force, bool $dryRun): array
    {
        $items = [];

        foreach (DB::table('banners')->whereNotNull('html')->orderBy('id')->get(['id', 'name', 'html', 'markdown']) as $banner) {
            $converted = $this->convert("banners:{$banner->name}", (string) $banner->html);
            $write = ! $dryRun && ($force || $banner->markdown === null);

            if ($write) {
                DB::table('banners')->where('id', $banner->id)->update(['markdown' => $converted->markdown]);
            }

            $items[] = new OperatorContentItem("banners:{$banner->name}", $converted, $write);
        }

        return $items;
    }

    /** @return list<OperatorContentItem> */
    private function gadgets(bool $force, bool $dryRun): array
    {
        $items = [];

        $rows = DB::table('gadget_configs')
            ->join('gadgets', 'gadgets.id', '=', 'gadget_configs.gadget_id')
            ->whereIn('gadgets.name', self::GADGETS)
            ->where('gadget_configs.name', self::GADGET_HTML)
            ->whereNotNull('gadget_configs.value')
            ->orderBy('gadgets.id')
            ->get(['gadgets.id', 'gadgets.name', 'gadgets.context', 'gadgets.zone', 'gadget_configs.value']);

        foreach ($rows as $row) {
            $location = "gadgets:{$row->id} ({$row->name}, {$row->context}/{$row->zone})";
            $converted = $this->convert($location, (string) $row->value);
            $target = ['gadget_id' => $row->id, 'name' => self::GADGET_MARKDOWN];
            $write = ! $dryRun && ($force || ! DB::table('gadget_configs')->where($target)->exists());

            if ($write) {
                DB::table('gadget_configs')->updateOrInsert($target, ['value' => $converted->markdown]);
            }

            $items[] = new OperatorContentItem($location, $converted, $write);
        }

        return $items;
    }

    private function convert(string $location, string $html): ConvertedOperatorHtml
    {
        $converted = OperatorHtmlMarkdown::convert($html);
        $bytes = strlen($converted->markdown);

        // Escaping can lengthen the text past the column, and a truncated value is worse than a failed run.
        if ($bytes > self::MAX_BYTES) {
            throw new RuntimeException("{$location}: the Markdown rewrite is {$bytes} bytes, over the ".self::MAX_BYTES.' the column holds — shorten the HTML and run the conversion again');
        }

        return $converted;
    }
}
