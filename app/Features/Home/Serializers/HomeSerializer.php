<?php

namespace App\Features\Home\Serializers;

use App\Features\Community\Serializers\CommunitySerializer;
use App\Features\Diary\Serializers\DiarySerializer;
use App\Features\Timeline\Serializers\TimelinePostSerializer;
use App\Models\Community;
use App\Models\Diary;
use App\Models\TimelinePost;
use Illuminate\Support\Collection;

/**
 * The Modern home dashboard: a capped digest of the three member-facing feeds — the timeline home
 * feed, the SNS-wide recent diaries, and the viewer's communities — each shaped by its own feature
 * serializer. These are previews (no pager); the page's "View all" links deep-link to the full lists.
 */
class HomeSerializer
{
    /**
     * @param  Collection<int, TimelinePost>  $timeline
     * @param  Collection<int, Diary>  $diaries
     * @param  Collection<int, Community>  $communities
     * @return array{timeline: list<array>, diaries: list<array>, communities: list<array>}
     */
    public static function dashboard(Collection $timeline, Collection $diaries, Collection $communities): array
    {
        return [
            'timeline' => $timeline->map([TimelinePostSerializer::class, 'entry'])->all(),
            'diaries' => $diaries->map([DiarySerializer::class, 'summary'])->all(),
            'communities' => $communities->map([CommunitySerializer::class, 'summary'])->all(),
        ];
    }
}
