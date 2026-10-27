<?php

namespace App\Upgrade\Runner;

use App\Mail\Template\MailTemplateService;
use App\Models\UpgradeState;
use App\Services\GadgetService;
use App\Services\NavigationService;
use App\Services\SnsSettingService;
use App\Services\TermService;
use App\Support\SnsSettingKey;
use App\Support\SurfaceMode;
use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\SourceSchema;
use App\Upgrade\StepRegistry;
use App\Upgrade\Steps\FileUpgrade;
use App\Upgrade\UpgradeStep;
use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Walks the steps in registry order, each in its own transaction with its checkpoint, and runs the
 * post-walk passes; console-free (an output closure) and registry-injectable (tests pass a step
 * subset). Checkpoints, resume, the naming epoch and the pass order are in docs/internals/upgrade.md.
 */
final class UpgradeRunner
{
    /**
     * Bumped whenever the upgrade's own identifiers (step class names, target tables) are renamed; a
     * resume under another epoch aborts. 2 = Message → DirectMessage, 3 = Community → Group,
     * 4 = CommunityTopic → GroupTopic, 5 = CommunityEvent → GroupEvent.
     */
    public const NAMING_EPOCH = 5;

    /** The `step_key` the epoch marker occupies. Not a step: no registry entry ever bears this name. */
    private const EPOCH_KEY = 'naming_epoch';

    /** @param list<UpgradeStep>|null $steps */
    public function __construct(
        private readonly InsertSelectCompiler $compiler,
        private readonly ?array $steps = null,
    ) {}

    /**
     * Preflights the source, then walks every step; returns false on an aborting source error or a
     * step failure (resumable). A missing required table/column aborts before any write (dry-run too);
     * an absent optional plugin group is created empty so its steps no-op, and dropped afterwards.
     */
    public function run(RunOptions $options, ?Closure $out = null, ?RunReport $report = null): bool
    {
        $report ??= new RunReport($out, $options->verbose, $options->dryRun);

        try {
            $ok = $this->execute($options, $report);
        } catch (Throwable $e) {
            // A failure a step already recorded (and, under verbose, rethrew) passes through; one nothing owns is the run's.
            if (! $report->isFailed()) {
                report($e);
                $report->failed('run', RunReport::failureMessage($e), $e);
            } elseif ($options->verbose) {
                throw $e;
            } else {
                report($e);
            }
            $ok = false;
        }

        $report->finish($ok);

        return $ok;
    }

    private function execute(RunOptions $options, RunReport $report): bool
    {
        $preflight = new SourcePreflight($this->steps(), SourceSchema::default());
        $source = $preflight->inspect($options->sourcePrefix, $options->sourceDatabase);

        // The file_bin bytes-complete check runs only on a clean structural verdict (it COUNTs the
        // source `file` that verdict guards) and only when this run migrates files.
        $fileBin = new FileBinMigration;
        $migratesFiles = in_array('files', $this->targetTables(), true);
        $fileBinError = $migratesFiles && ! $source->hasErrors()
            ? $fileBin->preflight($options->sourcePrefix, $options->sourceDatabase)
            : null;

        // Same shape for the mail templates: it reads the source rows the structural check guards, and
        // its errors are source states the translation step cannot survive, so they join this abort.
        $mailReport = ! $source->hasErrors() && in_array('mail_template_translations', $this->targetTables(), true)
            ? (new MailTemplatePreflight)->inspect($options->sourcePrefix, $options->sourceDatabase)
            : new MailTemplatePreflightReport([], []);

        // Also only on a clean structural verdict, since it counts rows in the guarded columns; an
        // unresolvable inactive-member reference or a member missing from the source is a state no
        // step can migrate correctly.
        $memberErrors = ! $source->hasErrors()
            ? self::memberReferenceErrors($preflight->inactiveMemberReferences($options->sourcePrefix, $options->sourceDatabase, $source->absentOptional))
            : [];

        // The activity routing's counts and the one-owner-per-file count, both reading rows the
        // structural verdict guards; their errors are rows a step would fail on mid-run.
        $activityReport = ! $source->hasErrors() && $this->readsSourceTable('activity_data')
            ? (new ActivityPreflight)->inspect($options->sourcePrefix, $options->sourceDatabase)
            : new PreflightReport([], []);
        $niceReport = ! $source->hasErrors()
            ? (new NicePreflight)->inspect($options->sourcePrefix, $options->sourceDatabase, array_values(array_diff($this->readSourceTables(), $source->absentOptional)))
            : new PreflightReport([], []);
        $sharedFileError = $migratesFiles && ! $source->hasErrors()
            ? (new FileOwnerPreflight)->inspect($options->sourcePrefix, $options->sourceDatabase, array_values(array_diff($this->readSourceTables(), $source->absentOptional)))
            : null;
        $termErrors = ! $source->hasErrors() && in_array('term_overrides', $this->targetTables(), true)
            ? (new TermOverridePreflight)->inspect($options->sourcePrefix, $options->sourceDatabase)
            : [];
        $uploadReport = ! $source->hasErrors() && $this->hasStep(FileUpgrade::class)
            ? (new UploadFileOwnerPreflight)->inspect($options->sourcePrefix, $options->sourceDatabase, array_values(array_diff($this->readSourceTables(), $source->absentOptional)))
            : new PreflightReport([], []);

        foreach (array_merge($source->tableErrors, $source->columnErrors, $fileBinError !== null ? [$fileBinError] : [], $mailReport->errors, $memberErrors, $activityReport->errors, $niceReport->errors, $sharedFileError !== null ? [$sharedFileError] : [], $termErrors) as $error) {
            $report->error($error);
        }

        // Before the abort, not after: these are already known, and an operator preparing a cutover
        // should see everything the source needs fixed in one run rather than one abort at a time.
        foreach (array_merge($mailReport->warnings, $activityReport->warnings, $niceReport->warnings, $uploadReport->warnings) as $warning) {
            $report->warning($warning);
        }

        if ($source->hasErrors() || $fileBinError !== null || $mailReport->hasErrors() || $memberErrors !== [] || $activityReport->hasErrors() || $niceReport->hasErrors() || $sharedFileError !== null || $termErrors !== []) {
            $report->abort('the OpenPNE 3 source did not pass preflight; nothing was migrated.');

            return false;
        }

        // After the structural verdict (the scan reads guarded columns) and before the plan/run split,
        // so a dry run shows the unrecognised names too.
        foreach ($preflight->unknownSourceNames($options->sourcePrefix, $options->sourceDatabase) as $table => $counts) {
            foreach ($counts as $name => $rows) {
                $report->warning(SourcePreflight::unknownSourceNameMessage($table, $name, $rows));
            }
        }

        if ($this->readsSourceTable('sns_config')) {
            $notice = new UncopiedSettingsNotice;
            foreach ($notice->inspect($options->sourcePrefix, $options->sourceDatabase) as $line) {
                $report->warning($line);
            }
            foreach ($notice->envHints($options->sourcePrefix, $options->sourceDatabase) as $key => $value) {
                $report->envHint($key, $value);
            }
        }

        if ($options->dryRun) {
            // Same compatibility gate as a real run, but read-only: a plan against legacy or
            // mismatched state must say so instead of PLANning steps that would never be allowed.
            if (! $this->claimNamingEpoch($report, dryRun: true)) {
                return false;
            }

            foreach ($source->absentOptional as $table) {
                $report->line("PLAN would create empty source table `{$table}` (".SourcePreflight::absentPluginMessage($table).')');
            }
            if ($migratesFiles) {
                $fileBin->plan($options->sourcePrefix, $options->sourceDatabase, $report);
            }
            (new PasswordWrap)->plan($report);
            (new ActivityTemplateTransform)->plan($report);
            (new EmojiTransform)->plan($report);
            (new SitePolicyMarkdownTransform)->plan($report);
            (new TalkReadCursorBackfill)->plan($report);
            (new BoardBumpBackfill)->plan($report);
            if ($this->readsSourceTable('sns_config')) {
                (new ClassicThemeSelection)->plan($report);
            }
            $report->line('PLAN would set surface_mode=classic_default if unset (keep the migrated site on the Classic surface).');

            return $this->walk($options, $report);
        }

        // Only now that the source is verified do we clear targets for --force-restart — otherwise a
        // bad source would let the restart delete existing data and then abort on the preflight.
        if ($options->forceRestart) {
            $this->reset($report);
        }

        // After the restart (which clears the marker with the rest of the state), so --force-restart
        // is exactly the escape hatch the abort message names.
        if (! $this->claimNamingEpoch($report)) {
            return false;
        }

        $created = $preflight->ensureExists($source->absentOptional, $options->sourcePrefix, $options->sourceDatabase, $report);

        try {
            if ($migratesFiles && ! $fileBin->snapshot($options->sourcePrefix, $options->sourceDatabase, $report)) {
                return false;
            }

            $walked = $this->walk($options, $report);

            // Not rescued: a map read during preflight is memoized, and a pass rendering with it
            // would commit the stock wording, so a clear that fails must fail the run.
            $this->forgetCachedMaps();

            // Wrap after the walk: the steps land the OpenPNE 3 MD5 verbatim (bcrypt is not
            // expressible in an INSERT...SELECT), and this pass converts it before the run can
            // complete — verify-upgrade holds the cutover to zero bare-MD5 rows.
            if ($walked) {
                $walked = (new PasswordWrap)->run($this->targetTables(), $report);
            }

            // Render the template activities after the walk: PHP over serialized parameters, and the
            // source is read again for them.
            if ($walked) {
                $walked = (new ActivityTemplateTransform)->run($this->targetTables(), $options->sourcePrefix, $options->sourceDatabase, $report);
            }

            // Rewrite carrier-emoji codes to Unicode after the walk: the mapping is per-row PHP, not
            // expressible in an INSERT...SELECT, and only now is every text table populated.
            if ($walked) {
                $walked = (new EmojiTransform)->run($this->targetTables(), $report);
            }

            // Reformat the policy bodies after the walk: OpenPNE 3 stored them as raw HTML honouring
            // newlines, OpenPNE 4 renders them as Markdown, and no INSERT...SELECT can bridge that.
            if ($walked) {
                $walked = (new SitePolicyMarkdownTransform)->run($this->targetTables(), $report);
            }

            // Only after the messages landed: the membership step leaves the read cursor at the
            // schema default, a wall-clock stamp the migrated history must not be measured against.
            if ($walked) {
                $walked = (new TalkReadCursorBackfill)->run($this->targetTables(), $report);
            }

            // Only after the comments landed: the thread steps write created_at as the placeholder.
            if ($walked) {
                $walked = (new BoardBumpBackfill)->run($this->targetTables(), $report);
            }

            // After the settings step: the skin is decided by two source tables at once, which no
            // INSERT...SELECT over one of them can express; gated on sns_config as the preflight is.
            if ($walked && $this->readsSourceTable('sns_config')) {
                $walked = (new ClassicThemeSelection)->run($this->targetTables(), $options->sourcePrefix, $options->sourceDatabase, $report);
            }

            // Only after the walk: FileUpgrade has populated `files`, so the FK rewire's existing-row
            // validation resolves, and no step touches file_bin.
            if ($walked && $migratesFiles) {
                $walked = $fileBin->move($options->sourcePrefix, $options->sourceDatabase, $report)
                    && $fileBin->rewire($report);
            }

            // Only after a full success (walk + BLOBs) so a post-walk failure never leaves the mode
            // stamped without the data behind it.
            if ($walked && $this->stampSurfaceMode($report) && $migratesFiles) {
                $report->nextStep('Each migrated picture is made on its first view; `php artisan openpne:image-cache warm --limit N` makes the newest ones ahead of time, run as often as wanted.');
            }

            return $walked;
        } finally {
            $preflight->drop($created, $options->sourcePrefix, $options->sourceDatabase);
            // Reported, not thrown: a cache store that is down must not replace the run's own outcome.
            rescue(fn () => $this->forgetCachedMaps());
        }
    }

    /**
     * Every step and every pass commits its own rows, so each map they wrote and the app caches for an
     * hour is stale after the walk and when the run ends, however it ends; an app warmed before the
     * cutover would otherwise serve the stock rows until the TTL.
     */
    private function forgetCachedMaps(): void
    {
        app(TermService::class)->clearCache();
        app(SnsSettingService::class)->clearCache();
        app(NavigationService::class)->clearCache();
        app(GadgetService::class)->clearCache();
        app(MailTemplateService::class)->clearCache();
    }

    /**
     * Insert-if-absent, so a re-run never clobbers a later operator switch (surface_mode has no
     * OpenPNE 3 source column). True only for the run that inserted it, which is the run that first
     * completed the site and so carries the next steps.
     */
    private function stampSurfaceMode(RunReport $report): bool
    {
        $inserted = DB::table('sns_settings')->insertOrIgnore([
            'key' => SnsSettingKey::SurfaceMode->value,
            'value' => SnsSettingKey::SurfaceMode->encode(SurfaceMode::ClassicDefault),
        ]);

        app(SnsSettingService::class)->clearCache();

        if ($inserted > 0) {
            $report->nextStep('Surface set to classic_default; switch to modern_only with `php artisan openpne:surface-mode modern_only` once the Modern migration is complete.');
        }

        return $inserted > 0;
    }

    /**
     * Stamps this run's naming epoch, or refuses to resume state written under another one: after a
     * rename the old checkpoint keys match no current step, so a resume would re-copy every renamed
     * step alongside the old rows. Public so the guard is exercisable without a MySQL source; with
     * $dryRun the check runs but never writes the marker.
     */
    public function claimNamingEpoch(?RunReport $report = null, bool $dryRun = false): bool
    {
        $report ??= new RunReport;

        $marker = UpgradeState::query()->where('step_key', self::EPOCH_KEY)->first();

        if ($marker === null) {
            // Checkpoints without a marker predate the marker itself (or were written by a version
            // that named steps differently), which is exactly the state this guard exists to refuse —
            // adopting it would leave the renamed steps looking never-run.
            if (UpgradeState::query()->exists()) {
                $report->abort('the upgrade state has checkpoints but no naming-epoch marker, so it was '
                    .'written under earlier step/table names and cannot be resumed — re-run with '
                    .'--force-restart to start over from an empty target.', RunReport::OUTCOME_BLOCKED);

                return false;
            }

            if (! $dryRun) {
                UpgradeState::create([
                    'step_key' => self::EPOCH_KEY,
                    'status' => UpgradeState::STATUS_COMPLETED,
                    'metadata' => ['epoch' => self::NAMING_EPOCH],
                    'finished_at' => now(),
                ]);
            }

            return true;
        }

        // An absent/garbled epoch is a marker this version cannot vouch for: treat it as a mismatch
        // rather than adopt the run.
        $found = $marker->metadata['epoch'] ?? null;
        if ($found === self::NAMING_EPOCH) {
            return true;
        }

        $report->abort(sprintf(
            'the upgrade state was written under naming epoch %s, but this version is epoch %d. '
            .'Its checkpoints name steps and tables that have since been renamed, so it cannot be resumed — '
            .'re-run with --force-restart to start over from an empty target.',
            var_export($found, true),
            self::NAMING_EPOCH,
        ), RunReport::OUTCOME_BLOCKED);

        return false;
    }

    /** The per-step loop: skip not-runnable / already-completed, else compile + (plan or run) each. */
    private function walk(RunOptions $options, RunReport $report): bool
    {
        foreach ($this->steps() as $step) {
            $key = class_basename($step);

            if ($step->pendingTargets() !== []) {
                $report->skipped($key, 'not runnable (pending: '.implode(', ', array_keys($step->pendingTargets())).')');

                continue;
            }

            if ($this->isCompleted($key)) {
                $report->skipped($key, 'already completed');

                continue;
            }

            $sql = $this->compiler->compile($step, $options->sourcePrefix, '', $options->sourceDatabase, null);

            if ($options->dryRun) {
                $report->planned($key);
                $report->line($sql);

                continue;
            }

            if (! $this->runStep($key, $sql, $report)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Clears the upgrade-owned target tables (verbatim ids would collide on re-insert) and the
     * checkpoints; DELETE, because an FK-referenced table like `files` refuses TRUNCATE even with
     * checks off (error 1701). The file_bin FK onto `files` is dropped first so the DELETE cannot
     * cascade into the BLOBs, which no step owns; rewire re-adds it.
     */
    public function reset(?RunReport $report = null): void
    {
        $report ??= new RunReport;

        $mysql = DB::connection()->getDriverName() === 'mysql';

        if ($mysql && in_array('files', $this->targetTables(), true)) {
            (new FileBinMigration)->dropForeignKey();
        }

        if ($mysql) {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
        }

        try {
            foreach ($this->targetTables() as $table) {
                DB::table($table)->delete();
            }
        } finally {
            if ($mysql) {
                DB::statement('SET FOREIGN_KEY_CHECKS=1');
            }
        }

        UpgradeState::query()->delete();
        $report->line('Reset: cleared upgrade state and target tables.');
    }

    /**
     * @param  array{refused: array<string, int>, dangling: array<string, int>}  $found
     * @return list<string>
     */
    private static function memberReferenceErrors(array $found): array
    {
        $errors = [];
        foreach ($found['refused'] as $reference => $rows) {
            $errors[] = SourcePreflight::inactiveMemberReferenceMessage($reference, $rows);
        }
        foreach ($found['dangling'] as $reference => $rows) {
            $errors[] = SourcePreflight::danglingMemberReferenceMessage($reference, $rows);
        }

        return $errors;
    }

    /** @return list<string> distinct step target tables in reverse run order (FK-safe delete order). */
    public function targetTables(): array
    {
        $tables = [];

        foreach (array_reverse($this->steps()) as $step) {
            $tables[$step->targetTable()] = true;
        }

        return array_keys($tables);
    }

    private function runStep(string $key, string $sql, RunReport $report): bool
    {
        $report->started($key);

        try {
            $affected = DB::transaction(function () use ($key, $sql): int {
                $state = UpgradeState::updateOrCreate(['step_key' => $key], [
                    'status' => UpgradeState::STATUS_RUNNING,
                    'started_at' => now(),
                    'finished_at' => null,
                    'rows_affected' => null,
                    'error' => null,
                ]);

                $affected = DB::affectingStatement($sql);

                $state->update([
                    'status' => UpgradeState::STATUS_COMPLETED,
                    'rows_affected' => $affected,
                    'finished_at' => now(),
                ]);

                return $affected;
            });

            $report->done($key, "{$affected} rows", $affected);

            return true;
        } catch (Throwable $e) {
            // The transaction rolled back the partial copy and the running checkpoint; record the
            // failure outside it so a resume sees this step as failed and the earlier ones as done.
            $message = RunReport::failureMessage($e);
            // rescue: a checkpoint write a dropped connection refuses must not move the FAIL off this step.
            rescue(static fn () => UpgradeState::updateOrCreate(['step_key' => $key], [
                'status' => UpgradeState::STATUS_FAILED,
                'error' => $message,
                'finished_at' => now(),
            ]));

            report($e);
            $report->failed($key, $message, $e);

            return false;
        }
    }

    private function isCompleted(string $key): bool
    {
        return UpgradeState::query()
            ->where('step_key', $key)
            ->where('status', UpgradeState::STATUS_COMPLETED)
            ->exists();
    }

    /** @return list<UpgradeStep> */
    private function steps(): array
    {
        return $this->steps ?? StepRegistry::all();
    }

    /** @param  class-string<UpgradeStep>  $class */
    private function hasStep(string $class): bool
    {
        foreach ($this->steps() as $step) {
            if ($step instanceof $class) {
                return true;
            }
        }

        return false;
    }

    private function readsSourceTable(string $table): bool
    {
        foreach ($this->steps() as $step) {
            if ($step->sourceTable() === $table) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function readSourceTables(): array
    {
        $tables = [];
        foreach ($this->steps() as $step) {
            $tables = array_merge($tables, $step->readSourceTables());
        }

        return array_values(array_unique($tables));
    }
}
