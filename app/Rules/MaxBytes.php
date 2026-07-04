<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Byte-length cap, as opposed to the framework `max:` rule's character count — a
 * multibyte string passes `max:72` while exceeding 72 bytes. Used where the boundary
 * is physical: bcrypt silently ignores everything past its 72nd input byte, so two
 * passwords sharing a 72-byte prefix would otherwise verify as the same.
 */
class MaxBytes implements ValidationRule
{
    public function __construct(private readonly int $bytes) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && strlen($value) > $this->bytes) {
            $fail(__('The :attribute must not be longer than :max bytes.', ['max' => $this->bytes]));
        }
    }
}
