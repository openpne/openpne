<?php

namespace App\Rules;

use App\Support\CommonPasswordList;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Rejects passwords on the bundled common-password blocklist (NIST SP 800-63B-4 §3.1.1.2 SHALL /
 * ASVS 5.0 6.2.4). The blocklist holds lowercase entries, so the candidate is folded here before the
 * lookup — a common password differing only in case is still common.
 */
class NotCommonPassword implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && CommonPasswordList::contains(mb_strtolower($value))) {
            $fail(__('This password is very commonly used, so it would be easily guessed. Choose a longer password, such as several unrelated words.'));
        }
    }
}
