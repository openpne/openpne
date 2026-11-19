<?php

namespace App\Database;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\SQLiteBuilder;

class SQLiteSchemaBuilder extends SQLiteBuilder
{
    /**
     * Laravel's table rebuild runs inside one Blueprint and resets sqlite_sequence to the highest id left, so the mark is restored here, before the caller's next insert (docs/internals/runtime.md, "Row ids").
     * Restored in `finally`: a rebuild that commits before a later statement of the same Blueprint fails has already lowered it.
     */
    protected function build(Blueprint $blueprint)
    {
        if ($this->connection->pretending()) {
            parent::build($blueprint);

            return;
        }

        // Resolved as the grammar resolves it, so a schema-qualified or prefixed table is the one read here.
        [$schema, $table] = $this->parseSchemaAndTable($blueprint->getTable(), withDefaultSchema: true);
        $table = $this->connection->getTablePrefix().$table;
        $before = $this->highWaterMark($schema, $table);

        try {
            parent::build($blueprint);
        } finally {
            if ($before !== null) {
                $this->restore($schema, $this->currentName($blueprint, $schema, $table), $before);
            }
        }
    }

    /** True when the CREATE TABLE statement has the keyword itself, not a string, identifier or comment spelling it. */
    public static function autoIncrements(string $createTable): bool
    {
        $code = preg_replace('/\'(?:[^\']|\'\')*\'|"(?:[^"]|"")*"|`(?:[^`]|``)*`|\[[^\]]*\]|--[^\n]*|\/\*.*?(?:\*\/|$)/s', ' ', $createTable);

        return preg_match('/\bautoincrement\b/i', (string) $code) === 1;
    }

    /** A failure can stop the Blueprint before its first rename, and a later rename always fails, as Laravel compiles each one from the original name. */
    private function currentName(Blueprint $blueprint, string $schema, string $table): string
    {
        if ($this->storedTable($schema, $table) !== null) {
            return $table;
        }

        foreach ($blueprint->getCommands() as $command) {
            if ($command->name === 'rename') {
                return $this->connection->getTablePrefix().$command->to;
            }
        }

        return $table;
    }

    /** @return array{string, string}|null the name as stored, which can differ in case from the one asked for, and the CREATE statement */
    private function storedTable(string $schema, string $name): ?array
    {
        $row = $this->connection->selectOne(
            "select name, sql from {$this->grammar->wrap($schema)}.sqlite_master where type = 'table' and name = ? collate nocase",
            [$name],
        );

        return $row === null ? null : [(string) $row->name, (string) $row->sql];
    }

    private function restore(string $schema, string $name, int $before): void
    {
        $table = $this->storedTable($schema, $name);

        // A table that no longer numbers its rows must not gain a sqlite_sequence row.
        if ($table === null || ! self::autoIncrements($table[1])) {
            return;
        }

        $now = $this->highWaterMark($schema, $table[0]);
        if ($now !== null && $now >= $before) {
            return;
        }

        $sequence = $this->grammar->wrap($schema).'.sqlite_sequence';

        // sqlite_sequence has no unique key on name, so no upsert.
        $this->connection->delete("delete from {$sequence} where name = ? collate nocase", [$table[0]]);
        $this->connection->insert("insert into {$sequence} (name, seq) values (?, ?)", [$table[0], $before]);
    }

    private function highWaterMark(string $schema, string $table): ?int
    {
        $schema = $this->grammar->wrap($schema);

        if (! $this->connection->scalar("select count(*) from {$schema}.sqlite_master where type = 'table' and name = 'sqlite_sequence'")) {
            return null;
        }

        $seq = $this->connection->scalar("select max(seq) from {$schema}.sqlite_sequence where name = ? collate nocase", [$table]);

        return $seq === null ? null : (int) $seq;
    }
}
