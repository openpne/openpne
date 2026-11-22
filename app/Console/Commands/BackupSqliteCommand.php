<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Database\SqliteBackup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class BackupSqliteCommand extends Command
{
    protected $signature = 'openpne:backup-sqlite {path : The file the copy goes to, in place of an earlier copy there once this one is whole}';

    protected $description = 'Copy the site\'s SQLite database, whole, while the site runs';

    public function handle(): int
    {
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'sqlite') {
            $this->error('The site\'s database is not SQLite.');

            return self::FAILURE;
        }
        if (! SqliteBackup::available()) {
            $this->error('PHP on the command line has no sqlite3 extension, which takes the copy.');

            return self::FAILURE;
        }
        try {
            $main = collect($connection->select('pragma database_list'))->firstWhere('name', 'main');
            $database = (string) ($main->file ?? '');
            if ($database === '') {
                $this->error('The site\'s database is not a file.');

                return self::FAILURE;
            }
            SqliteBackup::write($database, (string) $this->argument('path'));
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
