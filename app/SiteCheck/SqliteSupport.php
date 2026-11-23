<?php

declare(strict_types=1);

namespace App\SiteCheck;

use App\Database\SqliteBackup;
use PDO;
use Throwable;

class SqliteSupport
{
    /** The floor Laravel 13 states for SQLite; below 3.32 a statement takes at most 999 bound values (docs/internals/runtime.md, "Bound parameters"). */
    public const LIBRARY_MINIMUM = '3.26.0';

    public function hasPdo(): bool
    {
        return extension_loaded('pdo_sqlite');
    }

    public function hasExtension(): bool
    {
        return SqliteBackup::available();
    }

    /** The SQLite pdo_sqlite runs on, or null where it cannot be read. */
    public function libraryVersion(): ?string
    {
        if (! $this->hasPdo()) {
            return null;
        }
        try {
            $version = (new PDO('sqlite::memory:'))->query('select sqlite_version()')->fetchColumn();
        } catch (Throwable) {
            return null;
        }

        return is_string($version) ? $version : null;
    }

    /** Whether pdo_sqlite or the sqlite3 extension is here, so that what SQLite still lacks is worth naming. */
    public function partlyThere(): bool
    {
        return $this->hasPdo() || $this->hasExtension();
    }

    /**
     * What keeps SQLite from being chosen here, none where it can be; a version that cannot be read
     * is not taken for too old.
     *
     * @return list<Finding>
     */
    public function lacks(): array
    {
        $lacks = [];
        if (! $this->hasPdo()) {
            $lacks[] = Finding::warn('cli.sqlite', 'sqlite_no_pdo');
        } elseif (($version = $this->libraryVersion()) !== null && version_compare($version, self::LIBRARY_MINIMUM, '<')) {
            $lacks[] = Finding::warn('cli.sqlite', 'sqlite_old_library', ['version' => $version, 'minimum' => self::LIBRARY_MINIMUM]);
        }
        if (! $this->hasExtension()) {
            $lacks[] = Finding::warn('cli.sqlite', 'sqlite_no_extension');
        }

        return $lacks;
    }
}
