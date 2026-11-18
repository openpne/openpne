<?php

declare(strict_types=1);

namespace App\Upgrade\Runner;

use App\Services\GadgetService;
use App\Services\SnsSettingService;
use App\Support\OperatorContentConversion;
use App\Support\OperatorHtmlMarkdown;
use App\Support\SnsSettingKey;
use Illuminate\Support\Facades\DB;

/**
 * Copies the home's top information boxes into the Modern home notice once the walk is through
 * (docs/internals/upgrade.md, "Home notice"). Writes only over an absent or empty row: saving the
 * Landing page settings page writes '' for an untouched notice, and a completed site never reaches this.
 */
final class HomeNoticeStamp
{
    /** An information box on the home with content, left on Classic only. */
    public const W_NOT_COPIED = 'W-HOME-NOTICE-NOT-COPIED';

    public const W_TOO_LARGE = 'W-HOME-NOTICE-TOO-LARGE';

    private const GADGET = 'informationBox';

    private const ZONE = 'top';

    public function plan(RunReport $report): void
    {
        $report->line('PLAN would copy the home\'s top information boxes into home_notice if it is empty (the Modern home shows it on top).');
    }

    /** @param list<string> $targetTables this run's step target tables */
    public function run(array $targetTables, RunReport $report): void
    {
        if (! in_array('gadget_configs', $targetTables, true)) {
            return;
        }

        app(SnsSettingService::class)->clearCache();
        $topShown = in_array(self::ZONE, app(GadgetService::class)->activeZones('home'), true);

        $copied = [];
        $left = [];
        foreach ($this->boxes() as $box) {
            $markdown = trim(OperatorHtmlMarkdown::convert((string) $box->value)->markdown);
            if ($markdown === '') {
                continue;
            }
            if ($topShown && $box->zone === self::ZONE) {
                $copied[] = $markdown;
            } else {
                $left[] = "#{$box->id} ".($box->zone === self::ZONE ? 'top, which the home layout does not show' : $box->zone);
            }
        }

        if ($left !== []) {
            $report->warning(new Warning(
                self::W_NOT_COPIED,
                count($left).' information box(es) on the home stay on Classic only, not copied to the Modern home notice: '.implode('; ', $left).'.',
                count($left),
            ));
        }

        if ($copied === []) {
            return;
        }

        $notice = implode("\n\n", $copied);
        if (strlen($notice) > SnsSettingKey::HomeNotice->maxBytes()) {
            $report->warning(new Warning(
                self::W_TOO_LARGE,
                'The home\'s top information boxes come to '.strlen($notice).' bytes as Markdown, over the home notice\'s '.SnsSettingKey::HomeNotice->maxBytes().'; not copied.',
            ));

            return;
        }

        $key = SnsSettingKey::HomeNotice->value;
        $written = DB::table('sns_settings')->insertOrIgnore(['key' => $key, 'value' => $notice])
            ?: DB::table('sns_settings')->where('key', $key)->where('value', '')->update(['value' => $notice]);

        app(SnsSettingService::class)->clearCache();

        if ($written > 0) {
            $report->nextStep('The home\'s top information boxes were copied to the Modern home notice; from now on the two are separate, and the Modern one is edited on the Landing page settings page.');
        }
    }

    /** @return list<object{id: int, zone: string, value: string|null}> in the order Classic draws them */
    private function boxes(): array
    {
        return DB::table('gadgets')
            ->leftJoin('gadget_configs', function ($join): void {
                $join->on('gadget_configs.gadget_id', '=', 'gadgets.id')
                    ->where('gadget_configs.name', OperatorContentConversion::GADGET_HTML);
            })
            ->where('gadgets.context', 'home')
            ->where('gadgets.name', self::GADGET)
            ->orderByRaw('gadgets.sort_order IS NULL, gadgets.sort_order')
            ->orderBy('gadgets.id')
            ->get(['gadgets.id', 'gadgets.zone', 'gadget_configs.value'])
            ->all();
    }
}
