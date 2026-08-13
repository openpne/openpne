<?php

namespace App\Upgrade\Runner;

use App\Models\UpgradeState;
use App\Services\SnsSettingService;
use App\Support\SnsSettingKey;
use App\Support\SurfaceMode;
use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\SourceSchema;
use App\Upgrade\StepRegistry;
use App\Upgrade\UpgradeStep;
use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Walks the upgrade steps in dependency order, copying each into the OpenPNE 4 schema and
 * checkpointing it in openpne4_upgrade_state. Console-free (the command passes an output closure) and
 * registry-injectable (tests pass a step subset), so the orchestration is testable on its own.
 *
 * Each step runs in its own transaction wrapping the INSERT...SELECT and the checkpoint write — the
 * whole run cannot be one transaction at OpenPNE 3 data volumes — so completed ⟺ committed and a
 * re-run resumes from the first incomplete step without re-inserting verbatim ids.
 *
 * A checkpoint records only that a step ran, not the step definition it ran. Changing a step's column
 * mapping invalidates prior UpgradeState checkpoints: resuming a database that was upgraded or
 * interrupted under an older step set is unsupported — reset (--force-restart) and re-run from scratch.
 *
 * The one case the runner can detect on its own is a rename of the identifiers a checkpoint is keyed
 * by (step_key is a class basename, and the target tables it wrote are named too): NAMING_EPOCH is
 * bumped with each such rename and stamped into the state on the first run, so a resume under a
 * different epoch aborts instead of silently skipping steps whose old keys no longer exist.
 */
final class UpgradeRunner
{
    /**
     * Bumped whenever the upgrade's own identifiers are renamed (step class names, target tables).
     * 2 = the Message → DirectMessage rename. 3 = the Community → Group rename.
     */
    public const NAMING_EPOCH = 3;

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
    public function run(RunOptions $options, ?Closure $out = null): bool
    {
        $out ??= static fn (string $line): null => null;

        $preflight = new SourcePreflight($this->steps(), SourceSchema::default());
        $report = $preflight->inspect($options->sourcePrefix, $options->sourceDatabase);

        // file_bin (the BLOBs) has no step, so its bytes-complete check rides alongside the step
        // preflight — but only once the step preflight is clean, since it COUNTs the source `file` the
        // latter guards. Runs only when this run migrates files at all (the whole registry does).
        $fileBin = new FileBinMigration;
        $migratesFiles = in_array('files', $this->targetTables(), true);
        $fileBinError = $migratesFiles && ! $report->hasErrors()
            ? $fileBin->preflight($options->sourcePrefix, $options->sourceDatabase)
            : null;

        // Same shape for the mail templates: it reads the source rows the structural check guards, and
        // its errors are source states the translation step cannot survive, so they join this abort.
        $mailReport = ! $report->hasErrors() && in_array('mail_template_translations', $this->targetTables(), true)
            ? (new MailTemplatePreflight)->inspect($options->sourcePrefix, $options->sourceDatabase)
            : new MailTemplatePreflightReport([], []);

        // Same shape again: it counts rows in the source columns the structural check guards. An
        // inactive-member reference the upgrade will not resolve on its own, or a member missing from
        // the source, is a state no step can migrate correctly — so it joins this abort too.
        $memberErrors = ! $report->hasErrors()
            ? self::memberReferenceErrors($preflight->inactiveMemberReferences($options->sourcePrefix, $options->sourceDatabase))
            : [];

        foreach (array_merge($report->tableErrors, $report->columnErrors, $fileBinError !== null ? [$fileBinError] : [], $mailReport->errors, $memberErrors) as $error) {
            $out("ERROR {$error}");
        }

        // Before the abort, not after: these are already known, and an operator preparing a cutover
        // should see everything the source needs fixed in one run rather than one abort at a time.
        foreach ($mailReport->warnings as $warning) {
            $out("WARN {$warning}");
        }

        if ($report->hasErrors() || $fileBinError !== null || $mailReport->hasErrors() || $memberErrors !== []) {
            $out('Aborted: the OpenPNE 3 source did not pass preflight; nothing was migrated.');

            return false;
        }

        // Only now that the structure is verified: the scan reads columns the checks above guard.
        // Before the plan/run split, because an unrecognised name is a read-only observation about the
        // source and a dry run is the cheapest place to see it.
        foreach ($preflight->unknownSourceNames($options->sourcePrefix, $options->sourceDatabase) as $table => $counts) {
            foreach ($counts as $name => $rows) {
                $out('WARN '.SourcePreflight::unknownSourceNameMessage($table, $name, $rows));
            }
        }

        if ($options->dryRun) {
            // Same compatibility gate as a real run, but read-only: a plan against legacy or
            // mismatched state must say so instead of PLANning steps that would never be allowed.
            if (! $this->claimNamingEpoch($out, dryRun: true)) {
                return false;
            }

            foreach ($report->absentOptional as $table) {
                $out("PLAN would create empty source table `{$table}` (".SourcePreflight::absentPluginMessage($table).')');
            }
            if ($migratesFiles) {
                $fileBin->plan($options->sourcePrefix, $options->sourceDatabase, $out);
            }
            (new PasswordWrap)->plan($out);
            (new EmojiTransform)->plan($out);
            (new SitePolicyMarkdownTransform)->plan($out);
            $out('PLAN would set surface_mode=classic_default if unset (keep the migrated site on the Classic surface).');

            return $this->walk($options, $out);
        }

        // Only now that the source is verified do we clear targets for --force-restart — otherwise a
        // bad source would let the restart delete existing data and then abort on the preflight.
        if ($options->forceRestart) {
            $this->reset($out);
        }

        // After the restart (which clears the marker with the rest of the state), so --force-restart
        // is exactly the escape hatch the abort message names.
        if (! $this->claimNamingEpoch($out)) {
            return false;
        }

        $created = $preflight->ensureExists($report->absentOptional, $options->sourcePrefix, $options->sourceDatabase, $out);

        try {
            if ($migratesFiles) {
                $fileBin->snapshot($options->sourcePrefix, $options->sourceDatabase, $out);
            }

            $walked = $this->walk($options, $out);

            // Wrap after the walk: the steps land the OpenPNE 3 MD5 verbatim (bcrypt is not
            // expressible in an INSERT...SELECT), and this pass converts it before the run can
            // complete — verify-upgrade holds the cutover to zero bare-MD5 rows.
            if ($walked) {
                $walked = (new PasswordWrap)->run($this->targetTables(), $out);
            }

            // Rewrite carrier-emoji codes to Unicode after the walk: the mapping is per-row PHP, not
            // expressible in an INSERT...SELECT, and only now is every text table populated.
            if ($walked) {
                $walked = (new EmojiTransform)->run($this->targetTables(), $out);
            }

            // Reformat the policy bodies after the walk: OpenPNE 3 stored them as raw HTML honouring
            // newlines, OpenPNE 4 renders them as Markdown, and no INSERT...SELECT can bridge that.
            if ($walked) {
                $walked = (new SitePolicyMarkdownTransform)->run($this->targetTables(), $out);
            }

            // Migrate the BLOBs only after the walk: FileUpgrade (first step) has populated `files`, so
            // the move + the FK rewire's existing-row validation resolve. No later step touches file_bin.
            if ($walked && $migratesFiles) {
                $fileBin->move($options->sourcePrefix, $options->sourceDatabase, $out);
                $fileBin->rewire($out);
            }

            // Only after a full success (walk + BLOBs) so a post-walk failure never leaves the mode
            // stamped without the data behind it.
            if ($walked) {
                $this->stampSurfaceMode($out);
            }

            return $walked;
        } finally {
            $preflight->drop($created, $options->sourcePrefix, $options->sourceDatabase);
        }
    }

    /**
     * Establish the Classic-default surface for the migrated site. Insert-if-absent so a resume /
     * re-run never clobbers a later operator switch (openpne:surface-mode). surface_mode has no
     * OpenPNE 3 source column, so it is set here rather than copied by a step.
     */
    private function stampSurfaceMode(Closure $out): void
    {
        $inserted = DB::table('sns_settings')->insertOrIgnore([
            'key' => SnsSettingKey::SurfaceMode->value,
            'value' => SnsSettingKey::SurfaceMode->encode(SurfaceMode::ClassicDefault),
        ]);

        app(SnsSettingService::class)->clearCache();

        if ($inserted > 0) {
            $out('Surface set to classic_default; switch to modern_only with `php artisan openpne:surface-mode modern_only` once the Modern migration is complete.');
        }
    }

    /**
     * Stamp this run's naming epoch, or refuse to resume state written under another one.
     *
     * A checkpoint is keyed by a step's class basename and records rows written into named target
     * tables; after a rename, the old keys match no current step, so a resume would report every
     * renamed step as pending and re-copy into the new tables alongside the old rows. Detected here
     * rather than left to a runbook, because the failure is silent. Public alongside reset() so the
     * guard is exercisable without a MySQL source. With $dryRun the check runs but never writes the
     * marker — a plan stays read-only.
     */
    public function claimNamingEpoch(?Closure $out = null, bool $dryRun = false): bool
    {
        $out ??= static fn (string $line): null => null;

        $marker = UpgradeState::query()->where('step_key', self::EPOCH_KEY)->first();

        if ($marker === null) {
            // Checkpoints without a marker predate the marker itself (or were written by a version
            // that named steps differently), which is exactly the state this guard exists to refuse —
            // adopting it would leave the renamed steps looking never-run.
            if (UpgradeState::query()->exists()) {
                $out('Aborted: the upgrade state has checkpoints but no naming-epoch marker, so it was '
                    .'written under earlier step/table names and cannot be resumed — re-run with '
                    .'--force-restart to start over from an empty target.');

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

        $out(sprintf(
            'Aborted: the upgrade state was written under naming epoch %s, but this version is epoch %d. '
            .'Its checkpoints name steps and tables that have since been renamed, so it cannot be resumed — '
            .'re-run with --force-restart to start over from an empty target.',
            var_export($found, true),
            self::NAMING_EPOCH,
        ));

        return false;
    }

    /** The per-step loop: skip not-runnable / already-completed, else compile + (plan or run) each. */
    private function walk(RunOptions $options, Closure $out): bool
    {
        foreach ($this->steps() as $step) {
            $key = class_basename($step);

            if ($step->pendingTargets() !== []) {
                $out("SKIP {$key}: not runnable (pending: ".implode(', ', array_keys($step->pendingTargets())).')');

                continue;
            }

            if ($this->isCompleted($key)) {
                $out("SKIP {$key}: already completed");

                continue;
            }

            $sql = $this->compiler->compile($step, $options->sourcePrefix, '', $options->sourceDatabase, null);

            if ($options->dryRun) {
                $out("PLAN {$key}:");
                $out($sql);

                continue;
            }

            if (! $this->runStep($key, $sql, $out)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Resets for --force-restart: clears the upgrade-owned target tables (verbatim ids would otherwise
     * collide on re-insert) and the checkpoints. DELETE, not TRUNCATE — a FK-referenced table like
     * `files` refuses TRUNCATE even with checks off (error 1701). file_bin is no step's target and
     * holds the OpenPNE 3 BLOBs, so it is never cleared here — but its FK onto `files` is dropped first,
     * so DELETEing `files` cannot cascade into those BLOBs; the re-run's rewire re-adds it.
     */
    public function reset(?Closure $out = null): void
    {
        $out ??= static fn (string $line): null => null;

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
        $out('Reset: cleared upgrade state and target tables.');
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

    private function runStep(string $key, string $sql, Closure $out): bool
    {
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

            $out("DONE {$key}: {$affected} rows");

            return true;
        } catch (Throwable $e) {
            // The transaction rolled back the partial copy and the running checkpoint; record the
            // failure outside it so a resume sees this step as failed and the earlier ones as done.
            UpgradeState::updateOrCreate(['step_key' => $key], [
                'status' => UpgradeState::STATUS_FAILED,
                'error' => $e->getMessage(),
                'finished_at' => now(),
            ]);

            $out("FAIL {$key}: {$e->getMessage()}");

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
}
