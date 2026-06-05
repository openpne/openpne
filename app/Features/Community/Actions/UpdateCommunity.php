<?php

namespace App\Features\Community\Actions;

use App\Features\Community\CommunityMembership;
use App\Features\Community\Data\CommunityFormData;
use App\Features\Community\Exceptions\CommunityActionException;
use App\Features\Community\Exceptions\CommunityActionFailure;
use App\Models\Community;
use App\Models\CommunityCategory;
use App\Models\Member;

class UpdateCommunity
{
    public function __invoke(Member $actor, Community $community, CommunityFormData $data): void
    {
        if (! CommunityMembership::canManage($community, $actor)) {
            throw new CommunityActionException(CommunityActionFailure::NotManager);
        }

        // Keeping the community's current category is always allowed, even if it is admin-only —
        // only switching to a non-member-creatable category is refused (OpenPNE 3 checkCreatable).
        $keepsCurrentCategory = $data->categoryId === $community->community_category_id;
        if (! $keepsCurrentCategory && ! CommunityCategory::memberCreatable($data->categoryId)) {
            throw new CommunityActionException(CommunityActionFailure::CategoryNotAllowed);
        }

        $community->update([
            'name' => $data->name,
            'description' => $data->description,
            'register_policy' => $data->registerPolicy,
            'community_category_id' => $data->categoryId,
        ]);
    }
}
