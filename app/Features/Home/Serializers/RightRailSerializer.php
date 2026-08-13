<?php

namespace App\Features\Home\Serializers;

use App\Models\Group;
use App\Models\Member;
use Illuminate\Support\Collection;

/**
 * The shell's right rail (xl+ only): a grid of faces and one of the viewer's joined groups.
 * A person carries a circular avatar, a community a square image, so each item ships the URL and its
 * deep link; the client falls back to a neutral initial badge when the image is null.
 *
 * The faces grid names its own audience: `people.kind` says whether the rows are the viewer's
 * friends or an SNS-wide sample (the fallback while `friend` is switched off), and the client reads
 * it for the heading and the view-all link. One key, so a call site cannot ship both.
 *
 * Both grids ask for 180px: the rail is a fixed `w-80`, so its three-up tiles land at ~90px and 180
 * is the nearest whitelisted size to the 2x they need. Every other surface paints these far smaller.
 */
class RightRailSerializer
{
    /**
     * @param  'friends'|'members'  $peopleKind
     * @param  Collection<int, Member>  $people
     * @param  Collection<int, Group>  $groups
     * @return array{people: array{kind: 'friends'|'members', items: list<array{id: int, name: string, imageUrl: string|null, avatarColor: string|null, href: string}>}, joinedGroups: list<array{id: int, name: string, imageUrl: string|null, avatarColor: string|null, href: string}>}
     */
    public static function rail(string $peopleKind, Collection $people, Collection $groups): array
    {
        return [
            'people' => [
                'kind' => $peopleKind,
                'items' => $people->map(fn (Member $m): array => [
                    'id' => $m->getKey(),
                    'name' => $m->name,
                    'imageUrl' => $m->avatar?->file?->thumbnailUrl(180, 180, square: true),
                    'avatarColor' => $m->avatar_color?->hex(),
                    'href' => "/member/{$m->getKey()}",
                ])->all(),
            ],
            'joinedGroups' => $groups->map(fn (Group $c): array => [
                'id' => $c->getKey(),
                'name' => $c->name,
                'imageUrl' => $c->image?->thumbnailUrl(180, 180, square: true),
                // Groups have no chosen badge color; the shared RightRailItem shape keeps the key.
                'avatarColor' => null,
                'href' => "/groups/{$c->getKey()}",
            ])->all(),
        ];
    }
}
