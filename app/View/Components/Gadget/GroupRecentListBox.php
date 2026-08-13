<?php

namespace App\View\Components\Gadget;

use App\Features\Group\GroupPostTitle;
use App\Models\GroupEvent;
use App\Models\GroupTopic;
use App\Support\LocalizedDate;
use Illuminate\Support\Collection;
use Illuminate\View\Component;

/**
 * Shared base for the OpenPNE 3 community recent-list gadgets: maps a topic/event collection into the
 * rows the shared _group-recent-rows partial renders. Each concrete kind injects its own query and
 * passes its show route; the group and comment count are eager-loaded, so mapping stays query-free.
 */
abstract class GroupRecentListBox extends Component
{
    /** @var list<array{date: string, url: string, title: string, group: string}> */
    public array $entries = [];

    /** @param array<string, mixed> $config */
    protected static function limit(array $config): int
    {
        return max(1, (int) ($config['col'] ?? 5));
    }

    /**
     * @param  Collection<int, GroupTopic|GroupEvent>  $posts
     * @return list<array{date: string, url: string, title: string, group: string}>
     */
    protected static function toEntries(Collection $posts, string $routeName): array
    {
        $locale = app()->getLocale();

        return $posts->map(fn (GroupTopic|GroupEvent $post): array => [
            'date' => LocalizedDate::monthDay($post->updated_at, $locale),
            'url' => route($routeName, $post),
            'title' => GroupPostTitle::withCount($post),
            'group' => $post->group->name,
        ])->all();
    }
}
