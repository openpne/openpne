<?php

namespace App\View\Components\Gadget;

use App\Features\Timeline\Queries\RecentReplies;
use App\Features\Timeline\Queries\RowsPage;
use App\Models\TimelinePost;
use App\Support\Stream\StreamPage;
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

    /** The load-more cursor, null when the rows end here. */
    public ?string $olderCursor = null;

    /** @param array<string, mixed> $config */
    protected static function limit(array $config): int
    {
        return min(RowsPage::MAX, max(1, (int) ($config['limit'] ?? RowsPage::DEFAULT)));
    }

    /** @param  StreamPage<TimelinePost>  $page */
    protected function keep(StreamPage $page): void
    {
        $this->posts = $page->rows;
        $this->olderCursor = $page->olderCursor()?->__toString();
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
