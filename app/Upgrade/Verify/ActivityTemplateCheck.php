<?php

namespace App\Upgrade\Verify;

use App\Features\GroupTalk\TalkBody;
use App\Features\Timeline\Announcement;
use App\Models\UpgradeState;
use App\Support\SiteLocale;
use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\Runner\ActivityTemplateRenderer;
use App\Upgrade\Runner\EmojiMap;
use App\Upgrade\Runner\RunOptions;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

/**
 * Re-derives every migrated template row's body from the source and compares: a rendered row must
 * hold the renderer's text, a kept row its stored body after the emoji pass. Counting rows that
 * "look rendered" would pass a raw body that happens to end in a URL.
 */
final class ActivityTemplateCheck
{
    private const TABLES = [
        'timeline_posts' => Announcement::MAX,
        'group_messages' => TalkBody::MAX,
    ];

    private const CHUNK = 500;

    private const SAMPLE = 5;

    /**
     * @param  list<string>  $targetTables
     * @param  Closure(string, bool, string): void  $record
     */
    public function verify(RunOptions $options, array $targetTables, Closure $record): void
    {
        $renderer = app(ActivityTemplateRenderer::class);
        $source = InsertSelectCompiler::qualify($options->sourceDatabase, $options->sourcePrefix, 'activity_data');

        foreach (self::TABLES as $table => $max) {
            if (! in_array($table, $targetTables, true)) {
                continue;
            }

            $name = "activity_template:{$table}";
            $state = UpgradeState::query()->where('step_key', 'activity_template_'.$table)
                ->where('status', UpgradeState::STATUS_COMPLETED)->first();
            if ($state === null) {
                $record($name, false, 'not completed — no completed upgrade-state row for the template pass');

                continue;
            }
            $metadata = is_array($state->metadata) ? $state->metadata : [];
            $locale = (string) ($metadata['locale'] ?? SiteLocale::default());
            $rootUrl = (string) ($metadata['root_url'] ?? URL::to('/'));

            $mismatched = 0;
            $sample = [];
            $checked = 0;

            $liveRootUrl = URL::to('/');
            URL::forceRootUrl($rootUrl);
            try {
                $this->compare($renderer, $source, $table, $max, $locale, $mismatched, $sample, $checked);
            } finally {
                URL::forceRootUrl($liveRootUrl);
            }

            $record($name, $mismatched === 0, $mismatched === 0
                ? "{$checked} template rows hold their rendered body"
                : "{$mismatched} template row(s) differ from the render (e.g. ids ".implode(', ', $sample).") — rendered with locale {$locale} at {$rootUrl}; a term renamed since the upgrade differs the same way");
        }
    }

    /** @param  list<int>  $sample */
    private function compare(ActivityTemplateRenderer $renderer, string $source, string $table, int $max, string $locale, int &$mismatched, array &$sample, int &$checked): void
    {
        $cursor = 0;
        while (true) {
            $rows = DB::select(
                "SELECT `a`.`id`, `a`.`template`, `a`.`template_param`, `a`.`uri`, `a`.`body` AS `source_body`, `t`.`body` FROM {$source} AS `a`"
                ." JOIN `{$table}` AS `t` ON `t`.`id` = `a`.`id`"
                .' WHERE `a`.`template` IS NOT NULL AND `a`.`id` > ? ORDER BY `a`.`id` LIMIT '.self::CHUNK,
                [$cursor],
            );

            if ($rows === []) {
                break;
            }

            foreach ($rows as $row) {
                // The emoji pass ran over the stored body, rendered or kept, so the expectation gets it too.
                $expected = EmojiMap::convert($renderer->render((string) $row->template, $row->template_param, $row->uri, $locale, $max)['body'] ?? (string) $row->source_body);

                if ((string) $row->body !== $expected) {
                    $mismatched++;
                    if (count($sample) < self::SAMPLE) {
                        $sample[] = (int) $row->id;
                    }
                }
                $checked++;
            }

            $cursor = (int) end($rows)->id;
        }
    }
}
