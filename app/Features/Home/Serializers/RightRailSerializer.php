<?php

namespace App\Features\Home\Serializers;

use App\Models\Member;
use Illuminate\Support\Collection;

/**
 * The shell's right rail (xl+ only): a grid of faces. Each item ships its circular avatar URL and
 * its deep link; the client falls back to a neutral initial badge when the image is null.
 *
 * The grid names its own audience: `people.kind` says whether the rows are the viewer's friends or
 * an SNS-wide sample (the fallback while `friend` is switched off), and the client reads it for the
 * heading and the view-all link. One key, so a call site cannot ship both.
 *
 * The tiles ask for 180px: the rail is a fixed `w-80`, so its three-up tiles land at ~90px and 180
 * is the nearest whitelisted size to the 2x they need. Every other surface paints these far smaller.
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
