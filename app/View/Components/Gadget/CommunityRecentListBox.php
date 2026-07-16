<?php

namespace App\View\Components\Gadget;

use App\Features\Community\CommunityPostTitle;
use App\Models\CommunityEvent;
use App\Models\CommunityTopic;
use App\Support\LocalizedDate;
use Illuminate\Support\Collection;
use Illuminate\View\Component;

/**
 * Shared base for the OpenPNE 3 community recent-list gadgets: maps a topic/event collection into the
 * rows the shared _community-recent-rows partial renders. Each concrete kind injects its own query and
 * passes its show route; the community and comment count are eager-loaded, so mapping stays query-free.
 */
abstract class CommunityRecentListBox extends Component
{
    /** @var list<array{date: string, url: string, title: string, community: string}> */
    public array $entries = [];

    /** @param array<string, mixed> $config */
    protected static function limit(array $config): int
    {
        return max(1, (int) ($config['col'] ?? 5));
    }

    /**
     * @param  Collection<int, CommunityTopic|CommunityEvent>  $posts
     * @return list<array{date: string, url: string, title: string, community: string}>
     */
    protected static function toEntries(Collection $posts, string $routeName): array
    {
        $locale = app()->getLocale();

        return $posts->map(fn (CommunityTopic|CommunityEvent $post): array => [
            'date' => LocalizedDate::monthDay($post->updated_at, $locale),
            'url' => route($routeName, $post),
            'title' => CommunityPostTitle::withCount($post),
            'community' => $post->community->name,
        ])->all();
    }
}
