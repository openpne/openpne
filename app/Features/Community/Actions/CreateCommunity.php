<?php

namespace App\Features\Community\Actions;

use App\Features\Community\CommunityRole;
use App\Features\Community\Data\CommunityFormData;
use App\Features\Community\Exceptions\CommunityActionException;
use App\Features\Community\Exceptions\CommunityActionFailure;
use App\Models\Community;
use App\Models\CommunityCategory;
use App\Models\Member;
use Illuminate\Support\Facades\DB;

class CreateCommunity
{
    public function __invoke(Member $creator, CommunityFormData $data): Community
    {
        if (! CommunityCategory::memberCreatable($data->categoryId)) {
            throw new CommunityActionException(CommunityActionFailure::CategoryNotAllowed);
        }

        return DB::transaction(function () use ($creator, $data) {
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

            return $community;
        });
    }
}
