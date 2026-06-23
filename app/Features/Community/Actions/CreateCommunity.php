<?php

namespace App\Features\Community\Actions;

use App\Features\Community\CommunityRole;
use App\Features\Community\Data\CommunityFormData;
use App\Features\Community\Exceptions\CommunityActionException;
use App\Features\Community\Exceptions\CommunityActionFailure;
use App\Files\PostImages;
use App\Models\Community;
use App\Models\CommunityCategory;
use App\Models\Member;
use Illuminate\Http\UploadedFile;

class CreateCommunity
{
    public function __construct(private readonly PostImages $images) {}

    public function __invoke(Member $creator, CommunityFormData $data, ?UploadedFile $image = null): Community
    {
        if (! CommunityCategory::memberCreatable($data->categoryId)) {
            throw new CommunityActionException(CommunityActionFailure::CategoryNotAllowed);
        }

        // compensating() (not a bare transaction) so a failed top-image byte write rolls back
        // wholesale without orphaning bytes on a disk backend.
        return $this->images->compensating(function (callable $store) use ($creator, $data, $image): Community {
            $community = Community::create([
                'name' => $data->name,
                'description' => $data->description,
                'register_policy' => $data->registerPolicy,
                'community_category_id' => $data->categoryId,
            ]);

            // The creator is the sole admin (one admin per community in Phase A).
            $community->members()->create([
                'member_id' => $creator->getKey(),
                'role' => CommunityRole::Admin,
            ]);

            if ($image !== null) {
                $file = $store($image, 'community', (int) $community->getKey());
                $community->update(['file_id' => $file->getKey()]);
            }

            return $community;
        });
    }
}
