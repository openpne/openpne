<?php

namespace App\Features\CommunityTopic;

use App\Files\FileStorage;
use App\Files\FileUploader;
use App\Models\File;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Stores topic/comment image bytes with rollback-safe compensation.
 *
 * Multi-image-per-post is the first of its kind here (avatars are single, diaries have none), and
 * the storage seam is not transactional: FileUploader writes the bytes immediately, so when the
 * surrounding transaction rolls back, the File rows vanish but a disk backend's bytes would be
 * orphaned. FileUploader only compensates its own single inner failure, not a later failure in the
 * outer transaction (another image, the *_image insert, or the post's own save). So compensating()
 * tracks every File written through it and deletes their bytes best-effort if the transaction
 * fails. The residual race (commit itself fails after a successful disk write) is left to the
 * periodic orphan-file GC, as in FileUploader.
 */
class CommunityTopicImages
{
    /** OpenPNE 3 app_community_topic_max_image_file_num (default): images per topic or comment. */
    public const MAX_IMAGES = 3;

    public function __construct(
        private readonly FileUploader $uploader,
        private readonly FileStorage $storage,
    ) {}

    /**
     * Run $work in a transaction. $work receives a `store(upload, type, id): File` that persists
     * bytes and tracks the File; if the transaction throws, every tracked File's bytes are deleted
     * best-effort before the exception propagates. Returns $work's result.
     *
     * @template T
     *
     * @param  callable(callable(UploadedFile, string, int): File): T  $work
     * @return T
     */
    public function compensating(callable $work): mixed
    {
        $stored = [];
        $store = function (UploadedFile $upload, string $relatedType, int $relatedId) use (&$stored): File {
            $file = $this->uploader->store($upload, $relatedType, $relatedId);
            $stored[] = $file;

            return $file;
        };

        try {
            return DB::transaction(fn () => $work($store));
        } catch (Throwable $e) {
            foreach ($stored as $file) {
                $this->storage->delete($file);
            }

            throw $e;
        }
    }

    /**
     * Run $persist (which creates and returns the post) and attach $uploads to it as numbered
     * images (slot 1..N), all in one transaction. Returns the persisted post.
     *
     * @template TPost of Model
     *
     * @param  array<int, UploadedFile>  $uploads
     * @param  callable(): TPost  $persist
     * @param  callable(TPost): HasMany<Model, TPost>  $relation
     * @return TPost
     */
    public function attach(string $relatedType, array $uploads, callable $persist, callable $relation): Model
    {
        return $this->compensating(function (callable $store) use ($relatedType, $uploads, $persist, $relation): Model {
            $post = $persist();

            foreach (array_values($uploads) as $index => $upload) {
                $file = $store($upload, $relatedType, (int) $post->getKey());
                $relation($post)->create(['file_id' => $file->getKey(), 'number' => $index + 1]);
            }

            return $post;
        });
    }
}
