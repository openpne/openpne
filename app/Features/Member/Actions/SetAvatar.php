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
 * The member keeps a single primary image today. The replace must not destroy the
 * previous avatar if the new upload fails, so within the transaction only the DB
 * links are dropped (which roll back cleanly); the replaced Files — and their bytes,
 * which a disk backend deletes irreversibly outside the transaction — are purged only
 * AFTER commit. A row lock on the member serializes concurrent replaces so a double
 * submit cannot leave two primary rows.
 */
class SetAvatar
{
    public function __construct(private readonly FileUploader $uploader) {}

    public function __invoke(Member $member, UploadedFile $upload): MemberImage
    {
        [$image, $replaced] = DB::transaction(function () use ($member, $upload): array {
            $member->newQuery()->whereKey($member->getKey())->lockForUpdate()->first();

            $replaced = $member->images()->get();
            $member->images()->whereKey($replaced->modelKeys())->delete();

            $file = $this->uploader->store($upload, 'member', (int) $member->getKey());
            $image = $member->images()->create([
                'file_id' => $file->getKey(),
                'is_primary' => true,
            ]);

            return [$image, $replaced];
        });

        // Bytes are irreversible on a disk backend; purge only now the new avatar is committed.
        $replaced->each(fn (MemberImage $old) => $old->file?->delete());

        return $image;
    }
}
