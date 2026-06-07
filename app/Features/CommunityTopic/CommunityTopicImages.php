<?php

namespace App\Features\CommunityTopic;

use App\Files\FileStorage;
use App\Files\FileUploader;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Atomically persists a post (topic or comment) and attaches its uploaded images.
 *
 * Multi-image-per-post is the first of its kind here (avatars are single, diaries have none), and
 * the storage seam is not transactional: FileUploader writes the bytes immediately, so when this
 * outer transaction rolls back, the File rows vanish but a disk backend's bytes would be orphaned.
 * FileUploader only compensates its own single inner failure, not a later failure in this outer
 * transaction (a second image, the *_image insert, or the post's own save). So every File written
 * here is tracked and its bytes are deleted best-effort if the transaction fails. The residual race
 * (commit itself fails after a successful disk write) is left to the periodic orphan-file GC, as in
 * FileUploader.
 */
class CommunityTopicImages
{
    public function __construct(
        private readonly FileUploader $uploader,
        private readonly FileStorage $storage,
    ) {}

    /**
     * Run $persist (which creates and returns the post) and attach $uploads to it as numbered
     * images (slot 1..N), all in one transaction. Returns the persisted post.
     *
     * @template TPost of Model
     *
     * @param  array<int, UploadedFile>  $uploads
     * @param  callable(): TPost  $persist
     * @param  callable(TPost): HasMany<Model, TPost>  $relation  the post's images() relation
     * @return TPost
     */
    public function attach(string $relatedType, array $uploads, callable $persist, callable $relation): Model
    {
        $stored = [];

        try {
            return DB::transaction(function () use ($relatedType, $uploads, $persist, $relation, &$stored): Model {
                $post = $persist();

                foreach (array_values($uploads) as $index => $upload) {
                    $file = $this->uploader->store($upload, $relatedType, (int) $post->getKey());
                    $stored[] = $file;
                    $relation($post)->create(['file_id' => $file->getKey(), 'number' => $index + 1]);
                }

                return $post;
            });
        } catch (Throwable $e) {
            foreach ($stored as $file) {
                $this->storage->delete($file);
            }

            throw $e;
        }
    }
}
