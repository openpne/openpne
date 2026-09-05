<?php

namespace App\Files;

use App\Models\File;
use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;

/**
 * The connection is resolved per call from the File's own connection and never cached, so the bytes
 * land in the database its metadata is in even if that connection is reconfigured. There is no
 * constant-memory streaming out of a row, so a whole BLOB is buffered in memory on read and write
 * (docs/internals/file-storage.md, "Memory shape").
 */
class DbBlobFileStorage implements FileStorage
{
    public function writeStream(File $file, $stream): void
    {
        $contents = stream_get_contents($stream);

        if ($contents === false) {
            throw new RuntimeException("Unable to read the input stream for file [{$file->id}].");
        }

        $connection = DB::connection($file->getConnectionName());
        $now = now()->toDateTimeString();

        // Take the connection's own PDO so the write joins any transaction open on it.
        $pdo = $connection->getPdo();

        // Bind the bytes as a LOB: on both SQLite and MySQL, PARAM_STR can corrupt embedded NUL and
        // high bytes through text binding or emulated-prepare quoting.
        if ($this->exists($file)) {
            $statement = $pdo->prepare('update file_bin set bin = ?, updated_at = ? where file_id = ?');
            $statement->bindValue(1, $contents, PDO::PARAM_LOB);
            $statement->bindValue(2, $now, PDO::PARAM_STR);
            $statement->bindValue(3, $file->id, PDO::PARAM_INT);
        } else {
            $statement = $pdo->prepare('insert into file_bin (file_id, bin, created_at, updated_at) values (?, ?, ?, ?)');
            $statement->bindValue(1, $file->id, PDO::PARAM_INT);
            $statement->bindValue(2, $contents, PDO::PARAM_LOB);
            $statement->bindValue(3, $now, PDO::PARAM_STR);
            $statement->bindValue(4, $now, PDO::PARAM_STR);
        }

        $statement->execute();
    }

    public function readStream(File $file)
    {
        $row = DB::connection($file->getConnectionName())
            ->table('file_bin')
            ->where('file_id', $file->id)
            ->first(['bin']);

        if ($row === null) {
            throw new RuntimeException("No stored bytes for file [{$file->id}].");
        }

        $stream = fopen('php://temp', 'r+b');

        if ($stream === false) {
            throw new RuntimeException("Unable to open a temporary stream for file [{$file->id}].");
        }

        fwrite($stream, (string) $row->bin);
        rewind($stream);

        return $stream;
    }

    public function delete(File $file): void
    {
        DB::connection($file->getConnectionName())
            ->table('file_bin')
            ->where('file_id', $file->id)
            ->delete();
    }

    public function exists(File $file): bool
    {
        return DB::connection($file->getConnectionName())
            ->table('file_bin')
            ->where('file_id', $file->id)
            ->exists();
    }
}
