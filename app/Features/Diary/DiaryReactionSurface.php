<?php

namespace App\Features\Diary;

use App\Features\Reactions\ReactionSurface;
use App\Models\Diary;
use App\Models\DiaryComment;
use Illuminate\Database\Eloquent\Model;

final class DiaryReactionSurface implements ReactionSurface
{
    /** @param  Diary|DiaryComment  $reactable */
    public function hold(Model $reactable): bool
    {
        return DiaryThreadLock::hold($reactable);
    }

    /** Nothing polls the page, so no watermark moves. */
    public function touched(Model $reactable): void {}
}
