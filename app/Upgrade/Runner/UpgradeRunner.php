<?php

namespace App\Upgrade\Runner;

use App\Mail\Template\MailTemplateService;
use App\Models\UpgradeState;
use App\Services\GadgetService;
use App\Services\NavigationService;
use App\Services\SnsSettingService;
use App\Services\TermService;
use App\Support\ReleaseMarker;
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
    public const EPOCH_KEY = 'naming_epoch';

    /** The `step_key` of the completion marker, written once on the first full success; not a step either. */
    public const RUN_KEY = 'upgrade_completed';

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

            // After execute()'s finally dropped the created tables, inside this try so a failing
            // write is the run's failure and the next run stamps it; never on a dry run.
            if ($ok && ! $options->dryRun) {
                $this->stampCompletion();
            }
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
        // Read before the preflight (state table only, so a completed site whose OpenPNE 3 tables are gone still answers), never for --force-restart, the escape hatch every refusal names, its dry run included.
        $verdict = $options->forceRestart ? new ResumeVerdict(ResumeVerdict::FRESH) : $this->resumeVerdict();
        if ($verdict->verdict === ResumeVerdict::COMPLETED) {
            $report->line('Completed: '.$verdict->reason.'; nothing to do (openpne:upgrade-status reports what has changed since).');

            return true;
        }
        if ($verdict->verdict === ResumeVerdict::LEGACY_COMPLETE) {
            // Adopted rather than walked: a walk would run a step this build added against a site in service.
            if (! $options->dryRun) {
                $this->stampCompletion();
            }
            $report->line('Completed: the run finished before this version recorded the set of steps; nothing to do (the completion is recorded as unverified, and openpne:verify-upgrade tells whether every step is there).');

            return true;
        }
        if ($verdict->verdict === ResumeVerdict::BLOCKED) {
            $report->abort((string) $verdict->reason, RunReport::OUTCOME_BLOCKED);

            return false;
        }

        $preflight = new SourcePreflight($this->steps(), SourceSchema::default());
        $source = $preflight->inspect($options->sourcePrefix, $options->sourceDatabase);

        // The file_bin bytes-complete check runs only on a clean structural verdict (it COUNTs the
        // source `file` that verdict guards) and only when this run migrates files.
        $fileBin = new FileBinMigration;
        $migratesFiles = in_array('files', $this->targetTables(), true);
        $fileBinErrors = $migratesFiles && ! $source->hasErrors()
            ? $fileBin->preflight($options->sourcePrefix, $options->sourceDatabase)
            : [];

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
        // Evaluates every step's SELECT, so it needs the structural verdict the SELECTs assume.
        $zeroDateErrors = ! $source->hasErrors()
            ? (new ZeroDatePreflight($this->steps(), SourceSchema::default(), $this->compiler))->inspect($options->sourcePrefix, $options->sourceDatabase, $source->absentOptional)
            : [];

        foreach (array_merge($source->tableErrors, $source->columnErrors, $fileBinErrors, $mailReport->errors, $memberErrors, $activityReport->errors, $niceReport->errors, $sharedFileError !== null ? [$sharedFileError] : [], $termErrors, $zeroDateErrors) as $error) {
            $report->error($error);
        }

        // Before the abort, not after: these are already known, and an operator preparing a cutover
        // should see everything the source needs fixed in one run rather than one abort at a time.
        foreach (array_merge($mailReport->warnings, $activityReport->warnings, $niceReport->warnings, $uploadReport->warnings) as $warning) {
            $report->warning($warning);
        }

        if ($source->hasErrors() || $fileBinErrors !== [] || $mailReport->hasErrors() || $memberErrors !== [] || $activityReport->hasErrors() || $niceReport->hasErrors() || $sharedFileError !== null || $termErrors !== [] || $zeroDateErrors !== []) {
            $report->abort('the OpenPNE 3 source did not pass preflight; nothing was migrated.');

            return false;
        }

        // After the structural verdict (the scan reads guarded columns) and before the plan/run split,
        // so a dry run shows the unrecognised names and the absent plugins too.
        foreach ($source->absentOptional as $table) {
            $report->warning(new Warning(SourcePreflight::W_ABSENT_PLUGIN, SourcePreflight::absentPluginMessage($table)));
        }
        foreach ($preflight->unknownSourceNameWarnings($options->sourcePrefix, $options->sourceDatabase) as $warning) {
            $report->warning($warning);
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
            if ((new ClassicThemeSelection)->checkpointKeys($this->targetTables(), $this->sourceTables()) !== []) {
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

        // After the restart (which clears the epoch row with the rest of the state): the restart
        // is exactly the escape hatch the refusal names, and a fresh state is stamped here.
        $this->stampRun();

        $created = $preflight->ensureExists($source->absentOptional, $options->sourcePrefix, $options->sourceDatabase);

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
            if ($walked && (new ClassicThemeSelection)->checkpointKeys($this->targetTables(), $this->sourceTables()) !== []) {
                $walked = (new ClassicThemeSelection)->run($this->targetTables(), $options->sourcePrefix, $options->sourceDatabase, $report);
            }

            // Only after the walk: the FK target `files` must exist and hold every row the bytes pair
            // with, since the rewire validates nothing (verify's file_bin:orphans does).
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
     * The verdict and the stamp as one call, for a test without a MySQL source: refuses (abort) what
     * resumeVerdict() blocks, else stamps the epoch row unless $dryRun (a completed site needs no stamp).
     */
    public function claimNamingEpoch(?RunReport $report = null, bool $dryRun = false): bool
    {
        $report ??= new RunReport;
        $verdict = $this->resumeVerdict();

        if ($verdict->verdict === ResumeVerdict::BLOCKED) {
            $report->abort((string) $verdict->reason, RunReport::OUTCOME_BLOCKED);

            return false;
        }

        if (! $dryRun && $verdict->verdict !== ResumeVerdict::COMPLETED) {
            $this->stampRun();
        }

        return true;
    }

    /**
     * Reads the state table only (no source), so it is safe before the preflight and for the status
     * command. The completion marker is checked first: a rename or a changed step after completion
     * must never answer with --force-restart, which on a live site is the site's data.
     */
    public function resumeVerdict(): ResumeVerdict
    {
        $completed = UpgradeState::query()->where('step_key', self::RUN_KEY)->first();
        if ($completed !== null) {
            $at = $completed->metadata['completed_at'] ?? $completed->finished_at?->toIso8601String() ?? 'an earlier run';

            return new ResumeVerdict(ResumeVerdict::COMPLETED, "the upgrade of this site finished at {$at}");
        }

        $marker = UpgradeState::query()->where('step_key', self::EPOCH_KEY)->first();

        if ($marker === null) {
            // Checkpoints without a marker predate the marker itself (or were written by a version
            // that named steps differently), which is exactly the state this guard exists to refuse —
            // adopting it would leave the renamed steps looking never-run.
            if (UpgradeState::query()->exists()) {
                return new ResumeVerdict(ResumeVerdict::BLOCKED, 'the upgrade state has checkpoints but no naming-epoch marker, so it was '
                    .'written under earlier step/table names and cannot be resumed — re-run with '
                    .'--force-restart to start over from an empty target.');
            }

            return new ResumeVerdict(ResumeVerdict::FRESH);
        }

        // An absent/garbled epoch is a marker this version cannot vouch for: treat it as a mismatch
        // rather than adopt the run.
        $found = $marker->metadata['epoch'] ?? null;
        if ($found !== self::NAMING_EPOCH) {
            return new ResumeVerdict(ResumeVerdict::BLOCKED, sprintf(
                'the upgrade state was written under naming epoch %s, but this version is epoch %d. '
                .'Its checkpoints name steps and tables that have since been renamed, so it cannot be resumed — '
                .'re-run with --force-restart to start over from an empty target.',
                var_export($found, true),
                self::NAMING_EPOCH,
            ));
        }

        $metadata = (array) $marker->metadata;
        if (! array_key_exists('manifest', $metadata)) {
            return new ResumeVerdict(self::legacyLooksComplete() ? ResumeVerdict::LEGACY_COMPLETE : ResumeVerdict::LEGACY);
        }

        // Present but unreadable (another format, a garbled row, a null) is refused like a garbled epoch, not read as legacy.
        $raw = $metadata['manifest'];
        $format = is_array($raw) ? ($raw['format'] ?? null) : null;
        $stored = $format === RunManifest::FORMAT ? RunManifest::fromStored($raw) : null;
        if ($stored === null) {
            $why = $format === RunManifest::FORMAT ? 'its phases are not readable' : 'format '.var_export($format, true).' where this version reads '.RunManifest::FORMAT;

            return new ResumeVerdict(ResumeVerdict::BLOCKED, "the upgrade state carries a run manifest this version cannot read ({$why}), so it cannot be resumed — re-run with --force-restart to start over from an empty target.");
        }

        $difference = $this->manifest()->firstDifference($stored);
        if ($difference !== null) {
            return new ResumeVerdict(
                ResumeVerdict::BLOCKED,
                "the upgrade state was written under a different set of steps: {$difference}, so it cannot be resumed — re-run with --force-restart to start over from an empty target.",
                trim(explode(' ', $difference)[0], '`'),
            );
        }

        return new ResumeVerdict(ResumeVerdict::OK);
    }

    /**
     * Legacy state carries no manifest, so the set it ran under is unknown: it is taken as complete
     * when nothing is failed or running and the surface_mode row exists, which only a full success
     * (or an operator's openpne:surface-mode before one) writes.
     */
    public static function legacyLooksComplete(): bool
    {
        $rows = UpgradeState::query()->where('step_key', '!=', self::EPOCH_KEY);

        return (clone $rows)->exists()
            && ! (clone $rows)->whereIn('status', [UpgradeState::STATUS_RUNNING, UpgradeState::STATUS_FAILED])->exists()
            && DB::table('sns_settings')->where('key', SnsSettingKey::SurfaceMode->value)->exists();
    }

    /** This run's phases in the order they finish; the PHP phases list themselves out when they have no checkpoint to write. */
    public function manifest(): RunManifest
    {
        return RunManifest::build($this->steps(), self::phases(), $this->targetTables(), $this->sourceTables());
    }

    /**
     * Every phase of this run in manifest order with the checkpoint keys it writes: a step's own key,
     * a pass's keys for these targets, and the keys a pass may or may not write (file_bin's move).
     *
     * @return array<string, array{kind: string, version: string, keys: list<string>, optional: list<string>}>
     */
    public function phaseCheckpoints(): array
    {
        $out = [];
        foreach ($this->steps() as $step) {
            if ($step->pendingTargets() === []) {
                $out[class_basename($step)] = ['kind' => 'step', 'version' => $step->definition(), 'keys' => [class_basename($step)], 'optional' => []];
            }
        }
        foreach (self::phases() as $phase) {
            $keys = $phase->checkpointKeys($this->targetTables(), $this->sourceTables());
            if ($keys !== []) {
                $out[class_basename($phase)] = ['kind' => 'pass', 'version' => $phase->version(), 'keys' => $keys, 'optional' => $phase instanceof FileBinMigration ? FileBinMigration::optionalKeys() : []];
            }
        }

        return $out;
    }

    /** @return list<Phase> the post-walk passes in run order, then the file_bin migration */
    public static function phases(): array
    {
        return [new PasswordWrap, new ActivityTemplateTransform, new EmojiTransform, new SitePolicyMarkdownTransform, new TalkReadCursorBackfill, new BoardBumpBackfill, new ClassicThemeSelection, new FileBinMigration];
    }

    /** Creates the epoch row with this run's manifest when the state is fresh; an existing row is this build's (the verdict said so). */
    private function stampRun(): void
    {
        if (UpgradeState::query()->where('step_key', self::EPOCH_KEY)->exists()) {
            return;
        }

        UpgradeState::create([
            'step_key' => self::EPOCH_KEY,
            'status' => UpgradeState::STATUS_COMPLETED,
            'metadata' => ['epoch' => self::NAMING_EPOCH, 'manifest' => $this->manifest(), 'release' => ReleaseMarker::sha(), 'stamped_at' => now()->toIso8601String()],
            'finished_at' => now(),
        ]);
    }

    /**
     * The first full success wins. Vouches for the manifest only when this state's first write stamped
     * one and it is still the current one; legacy state is marked unverified rather than vouched for
     * with the current build.
     */
    private function stampCompletion(): void
    {
        $marker = UpgradeState::query()->where('step_key', self::EPOCH_KEY)->first();
        $stored = $marker === null ? null : RunManifest::fromStored((array) ($marker->metadata['manifest'] ?? []));
        $verified = $stored !== null && $stored->hash() === $this->manifest()->hash();

        UpgradeState::firstOrCreate(['step_key' => self::RUN_KEY], [
            'status' => UpgradeState::STATUS_COMPLETED,
            'finished_at' => now(),
            'metadata' => [
                'verified' => $verified,
                'manifest_hash' => $verified ? $stored->hash() : null,
                'release' => $verified ? ($marker->metadata['release'] ?? null) : null,
                'completed_at' => now()->toIso8601String(),
            ],
        ]);
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

            if (! $this->runStep($key, $sql, $step->definition(), $report)) {
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

    private function runStep(string $key, string $sql, string $definition, RunReport $report): bool
    {
        $report->started($key);

        try {
            $affected = DB::transaction(function () use ($key, $sql, $definition): int {
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
                    'metadata' => ['definition' => $definition],
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

    /** @return list<string> distinct step source (FROM) tables in run order */
    public function sourceTables(): array
    {
        return array_values(array_unique(array_map(static fn (UpgradeStep $s): string => $s->sourceTable(), $this->steps())));
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
