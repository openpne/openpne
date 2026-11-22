<?php

declare(strict_types=1);

namespace App\SiteCheck;

use App\Setup\BackupLine;
use App\Setup\SetupHost;
use Illuminate\Support\Facades\Process;
use PDO;
use Throwable;

class SqliteSupport
{
    /**
     * Below it, SQLite refuses a statement binding more than 999 values, which the app's batches of 1000 bind.
     * 3.31.1 also fails a migration's ADD COLUMN defaulting to CURRENT_TIMESTAMP.
     */
    public const LIBRARY_MINIMUM = '3.32.0';

    public function __construct(private readonly SetupHost $host) {}

    public function hasPdo(): bool
    {
        return extension_loaded('pdo_sqlite');
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

    /** The sqlite3 command's version, or null where it cannot be run or does not say. */
    public function commandVersion(string $tool): ?string
    {
        if (! $this->host->canSpawn()) {
            return null;
        }
        try {
            $result = Process::timeout(30)->run([$tool, '-version']);
        } catch (Throwable) {
            return null;
        }

        return $result->successful() && preg_match('/\A(\d+\.\d+\.\d+)/', trim($result->output()), $match) === 1 ? $match[1] : null;
    }

    /** Whether pdo_sqlite or the sqlite3 command is here, so that what SQLite still lacks is worth naming. */
    public function partlyThere(): bool
    {
        return $this->hasPdo() || BackupTools::sqlite() !== null;
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
        $tool = BackupTools::sqlite();
        if ($tool === null) {
            $lacks[] = Finding::warn('cli.sqlite', 'sqlite_no_command');
        } elseif (($version = $this->commandVersion($tool)) !== null && version_compare($version, BackupLine::SQLITE_MINIMUM, '<')) {
            $lacks[] = Finding::warn('cli.sqlite', 'sqlite_old_command', ['path' => $tool, 'version' => $version, 'minimum' => BackupLine::SQLITE_MINIMUM]);
        }

        return $lacks;
    }
}
