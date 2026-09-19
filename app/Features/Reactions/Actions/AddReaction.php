<?php

namespace App\Features\Reactions\Actions;

use App\Features\Reactions\Exceptions\ReactionRefused;
use App\Features\Reactions\ReactionSurface;
use App\Features\Reactions\ReactorLock;
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
            if (! ReactorLock::hold($member) || ! $surface->hold($reactable)) {
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
}
