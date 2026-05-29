<?php

namespace App\Upgrade;

/**
 * How one OpenPNE 4 target column is filled from the OpenPNE 3 source row:
 * either a straight copy of a source column, or a SQL expression over source columns.
 *
 * `uses` records which source columns an expression reads, so the matrix audit can
 * verify every source column is mapped or explicitly gapped (no silent drops).
 */
final class Column
{
    /** @param list<string> $uses */
    private function __construct(
        public readonly ?string $source = null,
        public readonly ?string $expr = null,
        public readonly array $uses = [],
    ) {}

    public static function source(string $name): self
    {
        return new self(source: $name, uses: [$name]);
    }

    /** @param list<string> $uses source columns the expression reads */
    public static function expr(string $sql, array $uses = []): self
    {
        return new self(expr: $sql, uses: $uses);
    }

    /** The SELECT-list fragment for this column. */
    public function selectSql(): string
    {
        return $this->expr ?? "`{$this->source}`";
    }
}
