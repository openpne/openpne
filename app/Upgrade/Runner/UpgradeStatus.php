<?php

declare(strict_types=1);

namespace App\Upgrade\Runner;

use App\Models\UpgradeState;
use App\Support\ReleaseMarker;
use Illuminate\Support\Facades\DB;
use JsonSerializable;

/**
 * Where a site's upgrade stands, read from the state table and this build's manifest; a report, not
 * a gate (docs/internals/upgrade.md, "Status"). `completed` is claimed only when the marker vouches
 * for the current manifest and every phase has its checkpoints.
 */
final class UpgradeStatus implements JsonSerializable
{
    public const NOT_STARTED = 'not_started';

    public const COMPLETED = 'completed';

    public const COMPLETED_UNVERIFIED = 'completed_unverified';

    public const BLOCKED = 'blocked';

    public const IN_PROGRESS = 'in_progress';

    /**
     * @param  array<string, mixed>  $fields
     * @param  list<array<string, mixed>>  $phases
     */
    private function __construct(
        public readonly string $status,
        public readonly array $fields,
        public readonly array $phases,
        public readonly string $manifestHash,
    ) {}

    public static function read(UpgradeRunner $runner): self
    {
        $rows = UpgradeState::query()->get()->keyBy('step_key');
        $manifest = $runner->manifest();
        $phases = self::phases($runner, $rows->all());

        if ($rows->isEmpty()) {
            return new self(self::NOT_STARTED, [], $phases, $manifest->hash());
        }

        $marker = $rows->get(UpgradeRunner::RUN_KEY);
        if ($marker !== null) {
            $meta = (array) $marker->metadata;
            $missing = self::incompletePhases($phases);
            $reason = match (true) {
                $missing !== [] => 'a phase of this build has no completed checkpoint: '.implode(', ', $missing),
                ! ($meta['verified'] ?? false) => 'completed under an unknown set of steps (state written before manifests)',
                ($meta['manifest_hash'] ?? null) !== $manifest->hash() => 'completed under a different set of steps than this build',
                default => null,
            };

            $fields = ['upgrade_completed_at' => $meta['completed_at'] ?? null, 'upgrade_release' => $meta['release'] ?? null];
            if ($reason !== null) {
                $fields['reason'] = $reason;
            }

            return new self($reason === null ? self::COMPLETED : self::COMPLETED_UNVERIFIED, $fields, $phases, $manifest->hash());
        }

        $verdict = $runner->resumeVerdict();
        if ($verdict->verdict === ResumeVerdict::LEGACY_COMPLETE) {
            $missing = self::incompletePhases($phases);

            return new self(self::COMPLETED_UNVERIFIED, ['upgrade_completed_at' => null, 'upgrade_release' => null, 'reason' => 'the run finished before this version recorded the set of steps; a re-run records the completion'
                .($missing === [] ? '' : '; phases without a completed checkpoint: '.implode(', ', $missing))], $phases, $manifest->hash());
        }
        if ($verdict->verdict === ResumeVerdict::BLOCKED) {
            return new self(self::BLOCKED, ['reason' => $verdict->reason, 'blocking_phase' => $verdict->blockingPhase], $phases, $manifest->hash());
        }

        $next = null;
        $failed = null;
        foreach ($phases as $phase) {
            if ($next === null && $phase['status'] !== 'completed') {
                $next = $phase['key'];
            }
            if ($failed === null && $phase['status'] === 'failed') {
                $failed = $phase['key'];
            }
        }

        return new self(self::IN_PROGRESS, ['next_phase' => $next, 'failed_phase' => $failed, 'legacy' => $verdict->verdict === ResumeVerdict::LEGACY], $phases, $manifest->hash());
    }

    /**
     * @param  array<string, UpgradeState>  $rows
     * @return list<array<string, mixed>>
     */
    private static function phases(UpgradeRunner $runner, array $rows): array
    {
        $out = [];
        foreach ($runner->phaseCheckpoints() as $key => $phase) {
            $present = [];
            foreach (array_merge($phase['keys'], $phase['optional']) as $checkpoint) {
                if (isset($rows[$checkpoint])) {
                    $present[$checkpoint] = $rows[$checkpoint];
                }
            }
            $required = count($phase['keys']);
            $completed = count(array_filter($present, static fn (UpgradeState $r): bool => $r->status === UpgradeState::STATUS_COMPLETED));
            $statuses = array_map(static fn (UpgradeState $r): string => $r->status, $present);
            $requiredCompleted = count(array_filter($phase['keys'], static fn (string $k): bool => isset($rows[$k]) && $rows[$k]->status === UpgradeState::STATUS_COMPLETED));

            $status = match (true) {
                in_array(UpgradeState::STATUS_FAILED, $statuses, true) => 'failed',
                in_array(UpgradeState::STATUS_RUNNING, $statuses, true) => 'running',
                $present === [] && $required === 0 => 'completed',
                $present === [] => 'not_run',
                $requiredCompleted === $required && $completed === count($present) => 'completed',
                default => 'partial',
            };

            $definition = $phase['kind'] === 'step' && isset($rows[$key]->metadata['definition'])
                ? $rows[$key]->metadata['definition'] === $phase['version']
                : null;

            $out[] = [
                'key' => $key,
                'kind' => $phase['kind'],
                'status' => $status,
                'rows' => $present === [] ? null : array_sum(array_map(static fn (UpgradeState $r): int => (int) $r->rows_affected, $present)),
                'finished_at' => $present === [] ? null : max(array_map(static fn (UpgradeState $r): ?string => $r->finished_at?->toIso8601String(), $present)),
                'definition_matches' => $definition,
            ];
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $phases
     * @return list<string>
     */
    private static function incompletePhases(array $phases): array
    {
        return array_values(array_map(
            static fn (array $p): string => $p['key'],
            array_filter($phases, static fn (array $p): bool => $p['status'] !== 'completed'),
        ));
    }

    /** @return list<string> */
    public function lines(): array
    {
        $lines = ["Status: {$this->status}"];
        foreach ($this->fields as $name => $value) {
            $lines[] = "{$name}: ".(is_bool($value) ? ($value ? 'yes' : 'no') : ($value ?? '-'));
        }
        foreach ($this->phases as $phase) {
            $detail = $phase['rows'] === null ? '' : " ({$phase['rows']} rows, {$phase['finished_at']})";
            $lines[] = "{$phase['key']}: {$phase['status']}{$detail}";
        }

        return $lines;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'site' => rescue(static fn (): string => DB::connection()->getDatabaseName(), report: false),
            'release' => rescue(static fn (): ?string => ReleaseMarker::sha(), report: false),
            'manifest_hash' => $this->manifestHash,
            'status' => $this->status,
        ] + $this->fields + ['phases' => $this->phases];
    }
}
