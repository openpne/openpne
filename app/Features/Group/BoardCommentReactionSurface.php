<?php

namespace App\Features\Group;

use App\Features\Reactions\ReactionSurface;
use App\Models\GroupEventComment;
use App\Models\GroupTopicComment;
use Illuminate\Database\Eloquent\Model;

final class BoardCommentReactionSurface implements ReactionSurface
{
    /** @param  GroupTopicComment|GroupEventComment  $reactable */
    public function hold(Model $reactable): bool
    {
        return BoardCommentLock::hold($reactable);
    }

    /** Nothing polls a board, so no watermark moves. */
    public function touched(Model $reactable): void {}
}
