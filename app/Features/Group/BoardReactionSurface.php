<?php

namespace App\Features\Group;

use App\Features\Reactions\ReactionSurface;
use App\Models\GroupEvent;
use App\Models\GroupEventComment;
use App\Models\GroupTopic;
use App\Models\GroupTopicComment;
use Illuminate\Database\Eloquent\Model;

final class BoardReactionSurface implements ReactionSurface
{
    /** @param  GroupTopic|GroupEvent|GroupTopicComment|GroupEventComment  $reactable */
    public function hold(Model $reactable): bool
    {
        return BoardLock::hold($reactable);
    }

    /** Nothing polls a board, so no watermark moves. */
    public function touched(Model $reactable): void {}
}
