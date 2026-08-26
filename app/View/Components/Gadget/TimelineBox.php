<?php

namespace App\View\Components\Gadget;

use App\Features\Timeline\Queries\RecentReplies;
use App\Features\Timeline\Queries\RowsPage;
use App\Models\TimelinePost;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\View\Component;

/**
 * Shared base for the OpenPNE 3 timeline gadgets: holds the posts each concrete kind renders through
 * the Classic timeline's shared _post partial. Each kind injects its own query and picks the subject.
 */
abstract class TimelineBox extends Component
{
    /** @var Collection<int, TimelinePost> */
    public Collection $posts;

    /** Whether a page past these rows exists — read from one row past the limit, not a count. */
    public bool $hasMore = false;

    /** @param array<string, mixed> $config */
    protected static function limit(array $config): int
    {
        return min(RowsPage::MAX, max(1, (int) ($config['limit'] ?? RowsPage::DEFAULT)));
    }

    /**
     * Keep $limit rows of a fetch that asked for one more, and remember whether that one came:
     * exactly $limit rows would otherwise offer a load-more that fetches nothing.
     *
     * @param  Collection<int, TimelinePost>  $rows
     */
    protected function keep(Collection $rows, int $limit): void
    {
        $this->hasMore = $rows->count() > $limit;
        $this->posts = $rows->take($limit);
    }

    /**
     * Give the rows their inline reply layer, as the timeline screens do — the gadgets render the
     * same partial. Skipped when there are no rows: the empty case is a plain `collect()`, which is
     * not the Eloquent collection an eager load runs on.
     */
    protected function attachInlineReplies(RecentReplies $recentReplies): void
    {
        if ($this->posts instanceof EloquentCollection) {
            $recentReplies($this->posts);
        }
    }
}
