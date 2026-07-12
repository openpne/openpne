<?php

namespace App\Features\Profile\Queries;

use App\Models\Profile;

/** Site-level gate: the preset birthday profile item exists (independent of its display flags). */
class BirthdayFieldExists
{
    public function __invoke(): bool
    {
        return Profile::query()->where('name', 'op_preset_birthday')->exists();
    }
}
