<?php

namespace App\Features\Profile\Queries;

use App\Models\Profile;

/** True regardless of the birthday item's display flags. */
class BirthdayFieldExists
{
    public function __invoke(): bool
    {
        return Profile::query()->where('name', 'op_preset_birthday')->exists();
    }
}
