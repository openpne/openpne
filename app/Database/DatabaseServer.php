<?php

declare(strict_types=1);

namespace App\Database;

use Illuminate\Support\Str;

/** See docs/internals/runtime.md, "Database server". */
final readonly class DatabaseServer
{
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
        return ! $this->mariaDb;
    }

    public function name(): string
    {
        return ($this->mariaDb ? 'MariaDB ' : 'MySQL ').$this->version;
    }
}
