<?php

namespace App\Files;

use App\Models\File;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * A disk backend's byte write is not part of the surrounding transaction, so compensating() tracks
 * every File it stores and deletes their bytes best-effort when that transaction fails. FileUploader
 * only undoes a failure inside its own call, never a later one in the outer transaction.
 */
class PostImages
{
    /** OpenPNE 3 app_community_topic/event_max_image_file_num (default): images per post. */
    public const MAX_IMAGES = 3;

    public function __construct(
        private readonly FileUploader $uploader,
        private readonly FileStorage $storage,
    ) {}

    /**
     * @template T
     *
     * @param  callable(callable(UploadedFile, string, int): File): T  $work
     * @return T
     */
    public function compensating(callable $work): mixed
    {
        $stored = [];
        $store = function (UploadedFile $upload, string $relatedType, int $relatedId) use (&$stored): File {
            try {
                // count($stored) is this upload's 0-based slot (nothing tracked yet for it).
                $file = $this->uploader->store($upload, $relatedType, $relatedId);
            } catch (ImageMetadataStripException $e) {
                throw $this->stripFailed($relatedType, count($stored), $e);
            }
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

    /**
     * The shared picker surfaces both `images` and `images.N`, so a multi-image form keys
     * `images.{slot}` where a single-image form keys `image`. A related type with no member-facing
     * field keeps the raw exception, which its own Filament caller converts.
     */
    private function stripFailed(string $relatedType, int $slot, ImageMetadataStripException $e): Throwable
    {
        $field = match ($relatedType) {
            'timelinePost', 'group' => 'image',
            'diary', 'diaryComment', 'groupTopic', 'groupTopicComment',
            'groupEvent', 'groupEventComment', 'directMessage', 'groupMessage' => 'images.'.$slot,
            default => null,
        };

        return $field === null
            ? $e
            : ValidationException::withMessages([$field => [ImageMetadataStripException::userMessage()]]);
    }
}
