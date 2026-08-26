<?php

namespace App\Features\Timeline\Queries;

use App\Models\TimelinePost;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The tail of each row's thread, for the Classic timeline's inline reply layer. One query for the
 * whole page (`limit()` on an eager-loaded HasMany compiles to a window-function partition), plus
 * one per relation the reply rows read — the Classic row partial is shared by four screens and three
 * gadgets, so a read per reply would multiply across all of them.
 *
 * Classic only: Modern's serializers carry a reply count, not the replies.
 */
class RecentReplies
{
    /** OpenPNE 3 showed the last ten under a row and offered the rest behind 以前のコメントを見る. */
    public const LIMIT = 10;

    /** What `_reply` renders: the author's avatar, the body's entity ranges, and the link card. */
    public const WITH = ['member.avatar.file', 'mentions', 'tags', 'linkCard.image'];

    /** @param  Collection<int, TimelinePost>  $posts */
    public function __invoke(Collection $posts): void
    {
        $posts->load(['recentReplies' => fn (HasMany $query) => $query->limit(self::LIMIT)->with(self::WITH)]);

        foreach ($posts as $post) {
            // Loaded newest first to cap at the tail; a thread reads oldest first.
            $post->setRelation('recentReplies', $post->recentReplies->reverse()->values());
        }
    }
}
