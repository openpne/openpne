<?php

namespace App\Upgrade;

use RuntimeException;

/**
 * Reads the OpenPNE 3 source DDL fixture (database/upgrade/openpne3-schema.sql).
 * Lets the seed tests create source tables from the real dump and lets the matrix
 * audit check mappings against the actual source columns.
 */
final class SourceSchema
{
    public function __construct(private readonly string $path) {}

    public static function default(): self
    {
        return new self(database_path('upgrade/openpne3-schema.sql'));
    }

    /**
     * The full `CREATE TABLE` statement for a source table. Pass $withoutForeignKeys
     * to drop FK constraints so the table can be created standalone (e.g. a single
     * feature's source table without its referenced tables in an isolated test).
     */
    public function createStatement(string $table, bool $withoutForeignKeys = false): string
    {
        $pattern = '/CREATE TABLE `'.preg_quote($table, '/').'` \(.*?\n\) ENGINE=.*?;/s';

        if (! preg_match($pattern, $this->contents(), $matches)) {
            throw new RuntimeException("Source table `{$table}` not found in {$this->path}");
        }

        $ddl = $matches[0];

        if ($withoutForeignKeys) {
            $ddl = preg_replace('/^\s*CONSTRAINT\b.*\n/m', '', $ddl); // drop FK lines
            $ddl = preg_replace('/,(\s*)\)/', '$1)', $ddl);           // repair the now-trailing comma
        }

        return $ddl;
    }

    /** @return list<string> column names of a source table, in definition order */
    public function columns(string $table): array
    {
        // Column lines start with an indented `name`; KEY/PRIMARY/CONSTRAINT/UNIQUE and
        // the `CREATE TABLE `t` (` line (backtick preceded by text) do not match.
        preg_match_all('/^\s+`([a-z0-9_]+)`\s+\S/im', $this->createStatement($table), $matches);

        return $matches[1];
    }

    private function contents(): string
    {
        $contents = file_get_contents($this->path);

        if ($contents === false) {
            throw new RuntimeException("Cannot read source schema fixture: {$this->path}");
        }

        return $contents;
    }
}
