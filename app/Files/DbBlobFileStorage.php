<?php

namespace App\Files;

use App\Models\File;
use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;

/**
 * Default file storage backend: keeps the bytes in the database `file_bin` table,
 * keyed by file_id (= File::id). This is the OSS default and the OpenPNE 3
 * heritage layout, so a whole site (including images) is one DB dump.
 *
 * The connection is resolved per call from $file->getConnectionName() (the
 * connection the File model is on), so the bytes always land in the same database
 * as their metadata. It is never cached on this instance, so the binding stays
 * correct if the File model's connection is ever reconfigured.
 *
 * Memory shape: the whole BLOB is buffered in PHP memory on read and write (a DB
 * limitation — there is no constant-memory streaming from a row), so readStream
 * materialises the row into php://temp. A single file's size is bounded by the
 * upload validation layer (a later slice) and ultimately by memory_limit /
 * max_allowed_packet; oversized writes surface as a DB/PDO error, not silently.
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

        // Bind the bytes as a LOB so embedded NUL and high bytes survive on both
        // SQLite and MySQL: PARAM_STR can corrupt binary data (text binding /
        // emulated-prepare quoting). Drive the statement through the connection's
        // PDO so the write joins any open transaction (FileUploader wraps the row
        // insert and this write in one transaction for atomicity).
        $pdo = $connection->getPdo();

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
        // Idempotent. The File `deleting` observer calls this before Eloquent
        // deletes the files row, and the file_bin FK cascade also removes it, so a
        // no-op on an already-absent row must not error.
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
