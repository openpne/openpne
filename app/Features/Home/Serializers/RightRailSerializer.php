<?php

namespace App\Features\Home\Serializers;

use App\Models\Community;
use App\Models\Member;
use Illuminate\Support\Collection;

/**
 * The shell's right rail (xl+ only): the viewer's friends and joined communities as thumbnail grids.
 * A person carries a circular avatar, a community a square image, so each item ships the URL and its
 * deep link; the client falls back to a neutral initial badge when the image is null.
 */
class RightRailSerializer
{
    /**
     * @param  Collection<int, Member>  $friends
     * @param  Collection<int, Community>  $communities
     * @return array{friends: list<array{id: int, name: string, imageUrl: string|null, href: string}>, joinedCommunities: list<array{id: int, name: string, imageUrl: string|null, href: string}>}
     */
    public static function rail(Collection $friends, Collection $communities): array
    {
        return [
            'friends' => $friends->map(fn (Member $m): array => [
                'id' => $m->getKey(),
                'name' => $m->name,
                'imageUrl' => $m->avatar?->file?->thumbnailUrl(76, 76, square: true),
                'href' => "/m/member/{$m->getKey()}",
            ])->all(),
            'joinedCommunities' => $communities->map(fn (Community $c): array => [
                'id' => $c->getKey(),
                'name' => $c->name,
                'imageUrl' => $c->image?->thumbnailUrl(120, 120, square: true),
                'href' => "/m/community/{$c->getKey()}",
            ])->all(),
        ];
    }
}
