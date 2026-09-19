<?php

namespace App\Features\Reactions\Actions;

use App\Features\Reactions\Exceptions\ReactionRefused;
use App\Features\Reactions\ReactionSurface;
use App\Models\Member;
use App\Models\Reaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class AddReaction
{
    /**
     * Authorization is the caller's.
     *
     * @return bool whether a row was written; a repeat of a held reaction is not
     *
     * @throws ReactionRefused
     */
    public function __invoke(Member $member, Model $reactable, string $emoji, ReactionSurface $surface): bool
    {
        return DB::transaction(function () use ($member, $reactable, $emoji, $surface): bool {
            if (! self::holdReactor($member) || ! $surface->hold($reactable)) {
                throw new ReactionRefused;
            }

            $now = now();

            $inserted = Reaction::query()->insertOrIgnore([
                'reactable_type' => $reactable->getMorphClass(),
                'reactable_id' => $reactable->getKey(),
                'member_id' => $member->getKey(),
                'emoji' => $emoji,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($inserted > 0) {
                $surface->touched($reactable);
            }

            return $inserted > 0;
        });
    }

    /**
     * The reactor's row before the surface's locks: the insert's foreign-key check takes this row
     * shared anyway, and a withdrawal holds it exclusively before it takes the surface's, so
     * reading it first keeps the two on one order (docs/internals/reactions.md, "One write path, one lock per surface").
     */
    public static function holdReactor(Member $member): bool
    {
        return DB::table('members')->where('id', $member->getKey())->sharedLock()->value('id') !== null;
    }
}
