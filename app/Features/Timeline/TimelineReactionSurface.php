<?php

namespace App\Features\Timeline;

use App\Features\Reactions\ReactionSurface;
use App\Models\TimelinePost;
use Illuminate\Database\Eloquent\Model;

final class TimelineReactionSurface implements ReactionSurface
{
    /** @param  TimelinePost  $reactable */
    public function hold(Model $reactable): bool
    {
        return TimelineThreadLock::hold($reactable);
    }

    /** Nothing polls a feed, so no watermark moves. */
    public function touched(Model $reactable): void {}
}
