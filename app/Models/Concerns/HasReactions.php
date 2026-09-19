<?php

namespace App\Models\Concerns;

use App\Models\Reaction;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasReactions
{
    /**
     * @return MorphMany<Reaction, $this> oldest first: the reactor list is capped, so this order
     *                                    decides which reactors it shows
     */
    public function reactions(): MorphMany
    {
        return $this->morphMany(Reaction::class, 'reactable')->orderBy('created_at')->orderBy('id');
    }
}
