<?php

declare(strict_types=1);

namespace App\Features\Member;

/** 255 is the column's width. */
final class MemberNameRules
{
    /** @return array<int, string> */
    public static function rules(): array
    {
        return ['required', 'string', 'max:255'];
    }
}
