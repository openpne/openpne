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
        $locale = SiteLocale::default();
        $source = InsertSelectCompiler::qualify($options->sourceDatabase, $options->sourcePrefix, 'activity_data');

        foreach (self::TABLES as $table => $max) {
            if (! in_array($table, $targetTables, true)) {
                continue;
            }

            $name = "activity_template:{$table}";
            $completed = UpgradeState::query()->where('step_key', 'activity_template_'.$table)
                ->where('status', UpgradeState::STATUS_COMPLETED)->exists();
            if (! $completed) {
                $record($name, false, 'not completed — no completed upgrade-state row for the template pass');

                continue;
            }

            $mismatched = [];
            $checked = 0;
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
                    $expected = $renderer->render((string) $row->template, $row->template_param, $row->uri, $locale, $max)['body']
                        ?? EmojiMap::convert((string) $row->source_body);

                    if ((string) $row->body !== $expected) {
                        $mismatched[] = (int) $row->id;
                    }
                    $checked++;
                }

                $cursor = (int) end($rows)->id;
            }

            $record($name, $mismatched === [], $mismatched === []
                ? "{$checked} template rows hold their rendered body"
                : count($mismatched).' template row(s) differ from the render (e.g. ids '.implode(', ', array_slice($mismatched, 0, self::SAMPLE)).')');
        }
    }
}
