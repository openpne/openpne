<?php

namespace App\Features\Home\Serializers;

use App\Models\Member;
use Illuminate\Support\Collection;

/**
 * The shell's right rail: a grid of faces whose `people.kind` says whether the rows are the viewer's
 * friends or an SNS-wide sample — one key, so a call site cannot ship both. The tiles ask for 180px,
 * the nearest whitelisted size to the 2x their ~90px cell needs (docs/internals/images.md, "Adding a
 * size").
 */
class RightRailSerializer
{
    /**
     * @param  'friends'|'members'  $peopleKind
     * @param  Collection<int, Member>  $people
     * @return array{people: array{kind: 'friends'|'members', items: list<array{id: int, name: string, imageUrl: string|null, avatarColor: string|null, isAi: bool, href: string}>}}
     */
    public static function rail(string $peopleKind, Collection $people): array
    {
        return [
            'people' => [
                'kind' => $peopleKind,
                'items' => $people->map(fn (Member $m): array => [
                    'id' => $m->getKey(),
                    'name' => $m->name,
                    'imageUrl' => $m->avatar?->file?->thumbnailUrl(180, 180, square: true),
                    'avatarColor' => $m->avatar_color?->hex(),
                    'isAi' => $m->isAiAccount(),
                    'href' => "/member/{$m->getKey()}",
                ])->all(),
            ],
        ];
    }
}
