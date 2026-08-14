<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One member's one emoji on one piece of content, whichever surface the content belongs to.
 *
 * `reactable_type` is a morph alias and is never written as a literal: a write goes through the
 * owner's morphMany (or its getMorphClass()), so the alias the row stores is the one the map's first
 * entry names and a later rename stays a morphMap edit.
 */
#[Fillable(['member_id', 'emoji'])]
class Reaction extends Model
{
    /** @return MorphTo<Model, $this> */
    public function reactable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<Member, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
