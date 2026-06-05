<?php

namespace App\Features\Member\Actions;

use App\Files\FileUploader;
use App\Models\Member;
use App\Models\MemberImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Sets a member's avatar from an uploaded image, replacing any existing one.
 *
 * The member keeps a single image (member_images.member_id is unique). The replace must
 * not destroy the previous avatar if the new upload fails, so within the transaction only
 * the DB link is dropped (which rolls back cleanly); the replaced File — and its bytes,
 * which a disk backend deletes irreversibly outside the transaction — is purged only AFTER
 * commit. A row lock on the member serializes concurrent replaces so a double submit cannot
 * collide on the unique key. The replaced row is read through a query (not the cached
 * relation, which may be stale) so its File is never missed.
 */
class SetAvatar
{
    public function __construct(private readonly FileUploader $uploader) {}

    public function __invoke(Member $member, UploadedFile $upload): MemberImage
    {
        [$image, $replaced] = DB::transaction(function () use ($member, $upload): array {
            $member->newQuery()->whereKey($member->getKey())->lockForUpdate()->first();

            $replaced = $member->avatar()->with('file')->first();
            $member->avatar()->delete();

            $file = $this->uploader->store($upload, 'member', (int) $member->getKey());
            $image = $member->avatar()->create(['file_id' => $file->getKey()]);

            return [$image, $replaced];
        });

        // Bytes are irreversible on a disk backend; purge only now the new avatar is committed.
        $replaced?->file?->delete();

        return $image;
    }
}
