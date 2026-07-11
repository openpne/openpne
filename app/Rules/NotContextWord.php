<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Throwable;

/**
 * Rejects a password that embeds a context word an attacker knowing the target would try first: the
 * site name, the member's own name or email local part, or the admin username (ASVS 5.0 6.2.4).
 * Context is resolved best-effort — any failure (an unresolved guard, no DB schema for sns_name()
 * mid-install) skips the check rather than blocking, since a false reject on unresolved context is
 * worse than a missed word. Accepted trade-off: a member whose name is a common short word cannot
 * embed it in a password.
 */
class NotContextWord implements DataAwareRule, ValidationRule
{
    /** (Sub)tokens shorter than this are too generic to gate on — the false-positive floor. */
    private const MIN_TOKEN = 4;

    /** Validation-data keys (last dot-segment) that carry a context word; Filament nests under `data.*`. */
    private const CONTEXT_KEYS = ['email', 'name', 'username'];

    /** @var array<string, mixed> */
    private array $data = [];

    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        $candidate = mb_strtolower($value);
        foreach ($this->subtokens($this->tokens()) as $subtoken) {
            if (str_contains($candidate, $subtoken)) {
                $fail(__('The password must not contain your name, your email address, or the name of this site.'));

                return;
            }
        }
    }

    /**
     * Raw context tokens from the three layers. The whole gathering is guarded: any throwable yields
     * no tokens, so the check silently passes rather than blocking on context it could not resolve.
     *
     * @return list<string>
     */
    private function tokens(): array
    {
        try {
            $tokens = [];

            foreach (Arr::dot($this->data) as $key => $val) {
                $leaf = Str::afterLast((string) $key, '.');
                if (! is_string($val) || $val === '' || ! in_array($leaf, self::CONTEXT_KEYS, true)) {
                    continue;
                }
                $tokens[] = $leaf === 'email' ? Str::before($val, '@') : $val;
            }

            if (($member = Auth::guard('member')->user()) !== null) {
                $tokens[] = Str::before((string) $member->email, '@');
                $tokens[] = (string) $member->name;
            }

            if (($admin = Auth::guard('admin')->user()) !== null) {
                $tokens[] = (string) $admin->username;
            }

            $tokens[] = sns_name();

            return array_values(array_filter($tokens, static fn (string $t): bool => $t !== ''));
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Each raw token lowercased and split on separators, plus the whole token with separators
     * stripped; (sub)tokens below the length floor are dropped.
     *
     * @param  list<string>  $tokens
     * @return list<string>
     */
    private function subtokens(array $tokens): array
    {
        $out = [];
        foreach ($tokens as $token) {
            $token = mb_strtolower($token);
            $parts = preg_split('/[.\-_+\s]+/', $token, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $parts[] = (string) preg_replace('/[.\-_+\s]+/', '', $token);
            foreach ($parts as $part) {
                if (mb_strlen($part) >= self::MIN_TOKEN) {
                    $out[$part] = true;
                }
            }
        }

        return array_keys($out);
    }
}
