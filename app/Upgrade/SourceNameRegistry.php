<?php

namespace App\Upgrade;

use InvalidArgumentException;

/**
 * Names in the KV source tables that a package outside this repository migrates (or drops) itself,
 * registered from its service provider so the preflight's unknown-name scan stops reporting them and
 * `openpne:upgrade-matrix` lists their disposition (docs/internals/upgrade.md, "KV config tables").
 */
final class SourceNameRegistry
{
    public const TABLES = ['member_config', 'community_config', 'notification_mail'];

    /** @var array<string, array<string, string>> table => name => disposition */
    private array $names = [];

    /** @var array<string, array<string, string>> table => prefix => disposition */
    private array $families = [];

    public function recognise(string $table, string $name, string $disposition): void
    {
        $this->assertTable($table);
        $this->names[$table][$name] = $disposition;
    }

    /** A family is every name starting with the prefix (`community_message_unsubscribe_` for `…_<id>` rows). */
    public function recogniseFamily(string $table, string $prefix, string $disposition): void
    {
        $this->assertTable($table);
        if ($prefix === '') {
            throw new InvalidArgumentException('A family prefix must not be empty.');
        }
        $this->families[$table][$prefix] = $disposition;
    }

    /** @return list<string> */
    public function names(string $table): array
    {
        return array_keys($this->names[$table] ?? []);
    }

    /** @return list<string> */
    public function prefixes(string $table): array
    {
        return array_keys($this->families[$table] ?? []);
    }

    /** @return array<string, string> name (a family as `prefix*`) => disposition */
    public function dispositions(string $table): array
    {
        $dispositions = $this->names[$table] ?? [];
        foreach ($this->families[$table] ?? [] as $prefix => $disposition) {
            $dispositions[$prefix.'*'] = $disposition;
        }

        return $dispositions;
    }

    private function assertTable(string $table): void
    {
        if (! in_array($table, self::TABLES, true)) {
            throw new InvalidArgumentException("`{$table}` is not a KV source table the preflight scans by name.");
        }
    }
}
