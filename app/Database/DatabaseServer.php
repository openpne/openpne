<?php

declare(strict_types=1);

namespace App\Database;

use Illuminate\Support\Str;

/** See docs/internals/runtime.md, "Database server". */
final readonly class DatabaseServer
{
    public const MARIADB_MINIMUM = '10.11';

    private function __construct(
        public bool $mariaDb,
        public string $version,
    ) {}

    public static function fromVersion(string $version): self
    {
        return new self(str_contains($version, 'MariaDB'), Str::before($version, '-'));
    }

    public function supported(): bool
    {
        return ! $this->mariaDb || version_compare($this->version, self::MARIADB_MINIMUM, '>=');
    }

    /** The connection driver this server takes; the other one's collations or branches do not fit it. */
    public function driver(): string
    {
        return $this->mariaDb ? 'mariadb' : 'mysql';
    }

    public function name(): string
    {
        return ($this->mariaDb ? 'MariaDB ' : 'MySQL ').$this->version;
    }
}
