<?php

namespace App\Features\Member\Actions;

use App\Files\FileUploader;
use App\Models\Member;
use App\Models\MemberImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * The row lock serializes concurrent replaces, so a double submit cannot collide on the unique
 * `member_images.member_id`. The replaced row is read by query, not through the cached relation,
 * so its File is never missed.
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
