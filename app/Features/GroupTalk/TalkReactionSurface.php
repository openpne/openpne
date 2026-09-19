<?php

namespace App\Features\GroupTalk;

use App\Features\Reactions\ReactionSurface;
use App\Models\GroupMessage;
use Illuminate\Database\Eloquent\Model;

final class TalkReactionSurface implements ReactionSurface
{
    /** @param  GroupMessage  $reactable */
    public function hold(Model $reactable): bool
    {
        return TalkWriteLock::hold($reactable);
    }

    /** @param  GroupMessage  $reactable */
    public function touched(Model $reactable): void
    {
        TalkReactionVersion::bump($reactable);
    }
}
