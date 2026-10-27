<?php

declare(strict_types=1);

namespace App\Upgrade\Runner;

use App\Upgrade\UpgradeStep;
use JsonSerializable;

/**
 * The ordered set of phases a run consists of, each with its version: the steps by definition hash,
 * the PHP phases by class VERSION. Its hash is what a resume is compared against and what the
 * completion marker vouches for (docs/internals/upgrade.md, "Checkpoints and resume").
 */
final class RunManifest implements JsonSerializable
{
    public const FORMAT = 1;

    public const ALGORITHM = 'sha256';

    /** @param  list<array{key: string, kind: string, version: string}>  $phases */
    private function __construct(public readonly array $phases) {}

    /**
     * @param  list<UpgradeStep>  $steps  runnable steps in run order (a pending step is left out)
     * @param  list<Phase>  $phases  the PHP phases in run order; one with no checkpoint key for these targets is left out
     * @param  list<string>  $targetTables
     * @param  list<string>  $sourceTables
     */
    public static function build(array $steps, array $phases, array $targetTables, array $sourceTables): self
    {
        $entries = [];
        foreach ($steps as $step) {
            if ($step->pendingTargets() !== []) {
                continue;
            }
            $entries[] = ['key' => class_basename($step), 'kind' => 'step', 'version' => $step->definition()];
        }
        foreach ($phases as $phase) {
            if ($phase->checkpointKeys($targetTables, $sourceTables) === []) {
                continue;
            }
            $entries[] = ['key' => class_basename($phase), 'kind' => 'pass', 'version' => (string) $phase::VERSION];
        }

        return new self($entries);
    }

    /** @param  array{phases?: mixed}  $stored  the metadata a run wrote */
    public static function fromStored(array $stored): ?self
    {
        $phases = $stored['phases'] ?? null;
        if (! is_array($phases)) {
            return null;
        }

        $entries = [];
        foreach ($phases as $phase) {
            if (! is_array($phase) || ! isset($phase['key'], $phase['kind'], $phase['version'])) {
                return null;
            }
            $entries[] = ['key' => (string) $phase['key'], 'kind' => (string) $phase['kind'], 'version' => (string) $phase['version']];
        }

        return new self($entries);
    }

    public function hash(): string
    {
        return hash(self::ALGORITHM, json_encode($this->phases, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_column($this->phases, 'key');
    }

    /**
     * The first phase on which the two differ, read in this manifest's order and then the other's:
     * "`<key>` (added|removed|changed)", or null when the hashes agree.
     */
    public function firstDifference(self $stored): ?string
    {
        if ($stored->hash() === $this->hash()) {
            return null;
        }

        $mine = array_column($this->phases, 'version', 'key');
        $theirs = array_column($stored->phases, 'version', 'key');

        foreach ($this->phases as $phase) {
            if (! isset($theirs[$phase['key']])) {
                return "`{$phase['key']}` (added)";
            }
            if ($theirs[$phase['key']] !== $phase['version']) {
                return "`{$phase['key']}` (changed)";
            }
        }
        foreach ($stored->phases as $phase) {
            if (! isset($mine[$phase['key']])) {
                return "`{$phase['key']}` (removed)";
            }
        }

        return '`'.($this->phases[0]['key'] ?? '').'` (reordered)';
    }

    /** @return array{format: int, algorithm: string, phases: list<array{key: string, kind: string, version: string}>, hash: string} */
    public function jsonSerialize(): array
    {
        return ['format' => self::FORMAT, 'algorithm' => self::ALGORITHM, 'phases' => $this->phases, 'hash' => $this->hash()];
    }
}
