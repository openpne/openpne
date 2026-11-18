<?php

namespace App\Upgrade\Verify;

use App\Upgrade\Audience;
use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\Runner\RunOptions;
use App\Upgrade\SourceRef;
use App\Upgrade\UpgradeStep;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Each declared audience column's target distribution against the one the step's own filter and
 * mapping give the live source, and every source value against the step's expected values
 * (docs/internals/upgrade.md, "Audience distribution").
 */
final class AudienceCheck
{
    private const SAMPLE = 5;

    public function __construct(private readonly InsertSelectCompiler $compiler) {}

    /**
     * @param  list<UpgradeStep>  $steps
     * @param  list<string>  $absent  optional plugin tables the source lacks
     * @param  Closure(string, bool, string): void  $record
     */
    public function verify(array $steps, RunOptions $options, array $absent, Closure $record): void
    {
        foreach ($steps as $step) {
            if ($step->pendingTargets() !== []) {
                continue;
            }

            foreach ($step->audiences() as $audience) {
                $this->compare($step, $audience, $options, $absent, $record);
            }
        }
    }

    /**
     * @param  list<string>  $absent
     * @param  Closure(string, bool, string): void  $record
     */
    private function compare(UpgradeStep $step, Audience $audience, RunOptions $options, array $absent, Closure $record): void
    {
        $select = $this->sourceSelect($step, $audience);
        $read = [$step->sourceTable(), ...SourceRef::tablesIn($step->effectiveFilter() ?? '')];
        foreach ($select as $sql) {
            $read = array_merge($read, SourceRef::tablesIn($sql));
        }
        $sourceRows = array_intersect($read, $absent) !== [] ? [] : $this->grouped($select,
            fn (string $list, string $tail): string => $this->compiler->compileQuery($step, $list, $tail, $options->sourcePrefix, $options->sourceDatabase));

        $labels = array_keys($audience->sources);
        $expected = [];
        $unexpected = [];
        $unexpectedGroups = 0;
        $distribution = [];
        foreach ($sourceRows as $row) {
            $partition = $this->partition($audience, $row);
            $values = [];
            $outside = false;
            foreach ($labels as $i => $label) {
                $value = $row["audience_s{$i}"];
                $values[] = "{$label}=".self::show($value);
                $outside = $outside || ! self::expects($audience->sources[$label][1], $value);
            }
            $key = serialize([$partition, $row['audience_mapped']]);
            $expected[$key] = ($expected[$key] ?? 0) + (int) $row['audience_rows'];

            $group = implode(' ', array_filter([$this->showPartition($partition), ...$values], static fn (string $part): bool => $part !== ''));
            $distribution[] = "{$group} → ".self::show($row['audience_mapped']).": {$row['audience_rows']}";
            if ($outside && $unexpectedGroups++ < self::SAMPLE) {
                $unexpected[] = "{$group} ({$row['audience_rows']} rows → ".self::show($row['audience_mapped']).')';
            }
        }

        $actual = [];
        foreach ($this->targetRows($step, $audience) as $row) {
            $actual[serialize([$this->partition($audience, $row), $row['audience_mapped']])] = (int) $row['audience_rows'];
        }

        $mismatches = [];
        foreach (array_keys($expected + $actual) as $key) {
            if (($expected[$key] ?? 0) !== ($actual[$key] ?? 0)) {
                [$partition, $mapped] = unserialize($key, ['allowed_classes' => false]);
                $mismatches[] = implode(' ', array_filter([$step->targetTable(), $this->showPartition($partition), "{$audience->column}=".self::show($mapped)], static fn (string $part): bool => $part !== ''))
                    .': source '.($expected[$key] ?? 0).', target '.($actual[$key] ?? 0);
            }
        }

        $problems = [];
        if ($unexpected !== []) {
            $more = $unexpectedGroups > self::SAMPLE ? ' and '.($unexpectedGroups - self::SAMPLE).' more' : '';
            $problems[] = "{$step->sourceTable()} values the step does not expect: ".implode(', ', $unexpected).$more;
        }
        if ($mismatches !== []) {
            $more = count($mismatches) > self::SAMPLE ? ' and '.(count($mismatches) - self::SAMPLE).' more' : '';
            $problems[] = implode('; ', array_slice($mismatches, 0, self::SAMPLE)).$more;
        }

        $record('audience:'.class_basename($step).'.'.$audience->column, $problems === [], $problems !== []
            ? implode(' — ', $problems)
            : ($distribution === [] ? 'no rows' : implode(', ', $distribution)));
    }

    /** @return array<string, string> alias => SQL over the step's FROM row */
    private function sourceSelect(UpgradeStep $step, Audience $audience): array
    {
        $columns = $step->columns();
        $select = [];
        foreach ($audience->partitionBy as $i => $column) {
            $select["audience_p{$i}"] = $columns[$column]->selectSql();
        }
        foreach (array_values($audience->sources) as $i => [$sql]) {
            $select["audience_s{$i}"] = $sql;
        }
        $select['audience_mapped'] = $columns[$audience->column]->selectSql();

        return $select;
    }

    /** @return list<array<string, string|int|null>> */
    private function targetRows(UpgradeStep $step, Audience $audience): array
    {
        $select = [];
        foreach ($audience->partitionBy as $i => $column) {
            $select["audience_p{$i}"] = "`{$column}`";
        }
        $select['audience_mapped'] = "`{$audience->column}`";
        $where = $step->targetFilter() !== null ? " WHERE {$step->targetFilter()}" : '';

        return $this->grouped($select, static fn (string $list, string $tail): string => "SELECT {$list} FROM `{$step->targetTable()}`{$where}{$tail}");
    }

    /**
     * Grouped as bytes: a _ci collation would fold a case or trailing-space variant into the value it
     * resembles and hide it from the expected-value test.
     *
     * @param  array<string, string>  $select  alias => SQL
     * @param  Closure(string, string): string  $query  select list, GROUP BY tail => statement
     * @return list<array<string, string|int|null>>
     */
    private function grouped(array $select, Closure $query): array
    {
        $list = [];
        foreach ($select as $alias => $sql) {
            $list[] = "CAST({$sql} AS BINARY) AS `{$alias}`";
        }
        $aliases = implode(', ', array_map(static fn (string $alias): string => "`{$alias}`", array_keys($select)));

        return array_map(
            static fn (object $row): array => (array) $row,
            DB::select($query(implode(', ', $list).', COUNT(*) AS `audience_rows`', "\nGROUP BY {$aliases}\nORDER BY {$aliases}")),
        );
    }

    /**
     * @param  array<string, string|int|null>  $row
     * @return array<string, string|null>
     */
    private function partition(Audience $audience, array $row): array
    {
        $partition = [];
        foreach ($audience->partitionBy as $i => $column) {
            $partition[$column] = $row["audience_p{$i}"] === null ? null : (string) $row["audience_p{$i}"];
        }

        return $partition;
    }

    /** @param  array<string, string|null>  $partition */
    private function showPartition(array $partition): string
    {
        return implode(' ', array_map(static fn (string $column, ?string $value): string => "{$column}=".self::show($value), array_keys($partition), $partition));
    }

    /** @param  list<int|string|null>  $expected */
    private static function expects(array $expected, string|int|null $value): bool
    {
        foreach ($expected as $candidate) {
            if ($candidate === null ? $value === null : $value !== null && (string) $candidate === (string) $value) {
                return true;
            }
        }

        return false;
    }

    /** Quoted unless a bare word, so a blank or a trailing space stays visible in the detail. */
    private static function show(string|int|null $value): string
    {
        return match (true) {
            $value === null => 'NULL',
            preg_match('/^\w+$/', (string) $value) === 1 => (string) $value,
            default => (string) json_encode((string) $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
        };
    }
}
