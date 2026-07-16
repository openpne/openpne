<?php

namespace App\Features\Community;

use App\Models\CommunityEvent;
use App\Models\CommunityTopic;

/**
 * OpenPNE 3 sprintf('%s(%d)', op_truncate($name, 36), $count): a topic/event list label — its name
 * truncated to display width 36 (full-width characters count as two, no ellipsis) followed by the
 * comment count with no separating space. Callers eager-load the count via withCount('comments'); a
 * missing count renders as 0 rather than triggering a per-row query.
 */
final class CommunityPostTitle
{
    private const WIDTH = 36;

    public static function withCount(CommunityTopic|CommunityEvent $post): string
    {
        return mb_strimwidth($post->name, 0, self::WIDTH, '').'('.($post->comments_count ?? 0).')';
    }
}
