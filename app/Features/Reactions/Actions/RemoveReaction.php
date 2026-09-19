<?php

namespace App\Features\Reactions\Actions;

use App\Features\Reactions\Exceptions\ReactionRefused;
use App\Features\Reactions\ReactionSurface;
use App\Models\Member;
use App\Models\Reaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class RemoveReaction
{
    /**
     * Authorization is the caller's. The emoji is not checked against the vocabulary: what a member
     * holds from a retired one must stay removable.
     *
     * @return bool whether a row was deleted
     *
     * @throws ReactionRefused
     */
    public function __invoke(Member $member, Model $reactable, string $emoji, ReactionSurface $surface): bool
    {
        return DB::transaction(function () use ($member, $reactable, $emoji, $surface): bool {
            if (! AddReaction::holdReactor($member) || ! $surface->hold($reactable)) {
                throw new ReactionRefused;
            }

            $deleted = Reaction::query()
                ->where('reactable_type', $reactable->getMorphClass())
                ->where('reactable_id', $reactable->getKey())
                ->where('member_id', $member->getKey())
                ->where('emoji', $emoji)
                ->delete();

            if ($deleted > 0) {
                $surface->touched($reactable);
            }

            return $deleted > 0;
        });
    }
}
