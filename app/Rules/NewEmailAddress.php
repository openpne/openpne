<?php

namespace App\Rules;

use Closure;
use Egulias\EmailValidator\EmailValidator;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Concerns\FilterEmailValidation;

/**
 * Accepts only addresses whose identifier-collation equality is byte equality once lowercased, so two
 * addresses that reach different mailboxes are never taken for the same (docs/internals/text-comparison.md, "Identifiers").
 */
class NewEmailAddress implements ValidationRule
{
    /** The HTML Living Standard's "valid e-mail address": printable ASCII only, no quoted local part, no address literal. */
    private const HTML_VALID_EMAIL = "/^[a-zA-Z0-9.!#$%&'*+\\/=?^_`{|}~-]+@[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*\\z/";

    /** @return list<mixed> */
    public static function rules(): array
    {
        return ['required', 'string', new self, 'max:255'];
    }

    public static function accepts(string $email): bool
    {
        return preg_match(self::HTML_VALID_EMAIL, $email) === 1
            && (new EmailValidator)->isValid($email, new FilterEmailValidation);
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || self::accepts($value)) {
            return;
        }

        if (preg_match('/[^\x00-\x7F]/', $value) === 1) {
            $fail(__('The email address may contain only ASCII letters, digits and symbols. A domain with other characters can be entered in its punycode form (xn--…).'));

            return;
        }

        $fail('validation.email')->translate();
    }
}
