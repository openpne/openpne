<?php

namespace App\Upgrade\Runner;

use App\Features\GroupTalk\TalkBody;
use App\Features\Timeline\Announcement;
use App\Models\UpgradeState;
use App\Support\SiteLocale;
use App\Upgrade\InsertSelectCompiler;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * Post-walk pass rewriting every migrated OpenPNE 3 template activity into the line and link
 * ActivityTemplateRenderer derives from the source row (docs/internals/upgrade.md, "Post-walk passes").
 * A chunk's rewrites commit with its checkpoint (cursor and reason counts), so a crash re-renders a
 * chunk without counting it twice.
 */
final class ActivityTemplateTransform
{
    /** Target table => the body length its authoring path allows. */
    private const TABLES = [
        'timeline_posts' => Announcement::MAX,
        'group_messages' => TalkBody::MAX,
    ];

    private const CHUNK = 500;

    public function plan(Closure $out): void
    {
        $out('PLAN would render every migrated OpenPNE 3 template activity (diary / topic / event lines) into the OpenPNE 4 wording with its link.');
    }

    /** @param  list<string>  $targetTables  this run's step target tables (skip tables the run does not own) */
    public function run(array $targetTables, string $sourcePrefix, ?string $sourceDatabase, Closure $out): bool
    {
        foreach (self::TABLES as $table => $max) {
            if (! in_array($table, $targetTables, true)) {
                continue;
            }

            $key = 'activity_template_'.$table;

            if ($this->isCompleted($key)) {
                $out("SKIP {$key}: already completed");

                continue;
            }

            if (! $this->transformTable($table, $max, $key, $sourcePrefix, $sourceDatabase, $out)) {
                return false;
            }
        }

        return true;
    }

    private function transformTable(string $table, int $max, string $key, string $sourcePrefix, ?string $sourceDatabase, Closure $out): bool
    {
        $renderer = app(ActivityTemplateRenderer::class);
        $locale = SiteLocale::default();
        $source = InsertSelectCompiler::qualify($sourceDatabase, $sourcePrefix, 'activity_data');

        try {
            $metadata = UpgradeState::query()->where('step_key', $key)->value('metadata');
            $cursor = is_array($metadata) ? (int) ($metadata['last_id'] ?? 0) : 0;
            $kept = is_array($metadata) ? (array) ($metadata['kept'] ?? []) : [];
            $rendered = is_array($metadata) ? (int) ($metadata['rendered'] ?? 0) : 0;

            UpgradeState::updateOrCreate(['step_key' => $key], [
                'status' => UpgradeState::STATUS_RUNNING,
                'started_at' => now(),
                'finished_at' => null,
                'rows_affected' => null,
                'error' => null,
            ]);

            while (true) {
                $rows = DB::select(
                    "SELECT `a`.`id`, `a`.`template`, `a`.`template_param`, `a`.`uri` FROM {$source} AS `a`"
                    ." JOIN `{$table}` AS `t` ON `t`.`id` = `a`.`id`"
                    .' WHERE `a`.`template` IS NOT NULL AND `a`.`id` > ? ORDER BY `a`.`id` LIMIT '.self::CHUNK,
                    [$cursor],
                );

                if ($rows === []) {
                    break;
                }

                DB::transaction(function () use ($rows, $renderer, $table, $max, $locale, $key, &$cursor, &$kept, &$rendered): void {
                    foreach ($rows as $row) {
                        $result = $renderer->render((string) $row->template, $row->template_param, $row->uri, $locale, $max);

                        if ($result['body'] === null) {
                            $kept[$result['reason']] = ($kept[$result['reason']] ?? 0) + 1;

                            continue;
                        }

                        DB::table($table)->where('id', $row->id)->update(['body' => $result['body']]);
                        $rendered++;
                    }

                    $cursor = (int) end($rows)->id;
                    // The render inputs travel with the checkpoint so verify re-renders under them, not under whatever the site runs later.
                    UpgradeState::updateOrCreate(['step_key' => $key], [
                        'metadata' => ['last_id' => $cursor, 'kept' => $kept, 'rendered' => $rendered, 'locale' => $locale, 'root_url' => URL::to('/')],
                    ]);
                });
            }

            UpgradeState::updateOrCreate(['step_key' => $key], [
                'status' => UpgradeState::STATUS_COMPLETED,
                'rows_affected' => $rendered,
                'finished_at' => now(),
            ]);

            $out("DONE {$key}: {$rendered} rows");
            foreach ($kept as $reason => $count) {
                $out('WARN '.self::keptMessage($table, (string) $reason, (int) $count));
            }

            return true;
        } catch (Throwable $e) {
            UpgradeState::updateOrCreate(['step_key' => $key], [
                'status' => UpgradeState::STATUS_FAILED,
                'error' => $e->getMessage(),
                'finished_at' => now(),
            ]);

            $out("FAIL {$key}: {$e->getMessage()}");

            return false;
        }
    }

    public static function keptMessage(string $table, string $reason, int $rows): string
    {
        $why = match ($reason) {
            ActivityTemplateRenderer::UNKNOWN_TEMPLATE => 'OpenPNE 4 knows no such template',
            ActivityTemplateRenderer::BAD_PARAMS => 'their template_param could not be read',
            ActivityTemplateRenderer::NO_LINK => 'their uri names no OpenPNE 4 page',
            ActivityTemplateRenderer::UNFIT => 'the link alone exceeds the body length',
            default => $reason,
        };

        return "{$rows} template row(s) in `{$table}` keep their stored body: {$why}.";
    }

    private function isCompleted(string $key): bool
    {
        return UpgradeState::query()
            ->where('step_key', $key)
            ->where('status', UpgradeState::STATUS_COMPLETED)
            ->exists();
    }
}
