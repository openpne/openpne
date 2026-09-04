# File storage

The bytes of an uploaded file live behind [`FileStorage`](../../app/Files/FileStorage.php), OpenPNE's
own seam rather than a Laravel filesystem disk. The seam takes the `File` entity, not a path: the
default backend keys bytes by `file_id` in the frozen `file_bin` table, which a path-string
abstraction cannot address without re-deriving the id from `files` on every call, while the entity
already carries both the id and the `name` token. Each backend then uses its native key —
[`DbBlobFileStorage`](../../app/Files/DbBlobFileStorage.php) by `file_id`,
[`DiskFileStorage`](../../app/Files/DiskFileStorage.php) by `name`.

The contract is the four byte-level operations only. Delivery is not here: every fetch goes through
an app route that streams the bytes whatever the backend is (`File::url()`, `File::publicUrl()`, the
`/cache/img` variants), so it stays backend-independent and policy-gated and no caller holds a disk
URL. `delete()` is idempotent on every backend — a missing object is not an error.

## The two backends

`openpne.files.disk` selects one. `blob` (the default) keeps the bytes in the database, which is the
OpenPNE 3 heritage layout and what makes a whole site, images included, one DB dump. Any other value
names a disk declared in `config/filesystems.php`; register an S3 disk there before pointing
`openpne.files.disk` at it. A disk backend writes objects with private visibility, since nothing
addresses them by disk URL.

## Memory shape

There is no constant-memory streaming out of a database row, so the DB-blob backend buffers a whole
BLOB in PHP memory on read and on write, and `readStream()` materialises the row into `php://temp`. A
single file is bounded by the upload validation layer and ultimately by `memory_limit` /
`max_allowed_packet`; an oversized write surfaces as a DB error rather than silently truncating.
Readers that work to a budget bound the read themselves ([images.md](images.md), [mcp.md](mcp.md)).

## Writing an upload

[`FileUploader`](../../app/Files/FileUploader.php) writes the `files` row and the bytes inside one DB
transaction. For the DB-blob backend that is fully atomic, both rows being in the same database. A
disk backend's physical write cannot join the transaction, so a failure after the bytes were written
is compensated in `FileUploader` itself — not in `FileObserver`, because a rollback never fires the
`deleting` event — and only when the row was saved, since a `files.name` collision means the key
belongs to a pre-existing file whose bytes must survive.

The residual race is accepted: if the commit fails after a successful disk write and the compensating
delete does not run, the bytes are unreachable with no metadata row pointing at them and only waste
space. A write that spans several files inside a wider transaction needs
[`PostImages`](../../app/Files/PostImages.php), whose `compensating()` owns that transaction and
tracks every file it stored ([group-talk.md](group-talk.md)).
