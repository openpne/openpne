<?php

namespace App\Features\Reactions;

use Illuminate\Database\Eloquent\Model;

/**
 * What a reaction write needs from the content it lands on (docs/internals/reactions.md, "One write
 * path, one lock per surface").
 */
interface ReactionSurface
{
    /**
     * Called inside the write's transaction: take the surface's locks in its one order and re-read the
     * content under them, false when it is gone.
     */
    public function hold(Model $reactable): bool;

    /** Called under the same locks, only after a row was actually inserted or deleted. */
    public function touched(Model $reactable): void;
}
