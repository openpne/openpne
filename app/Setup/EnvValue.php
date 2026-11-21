<?php

declare(strict_types=1);

namespace App\Setup;

/** The answers openpne:setup refuses before writing them into `.env` (docs/internals/runtime.md, "Site setup"). */
final class EnvValue
{
    /** A key under console.setup saying why the value would not reach the app as typed, or null when it would. */
    public static function problem(string $value): ?string
    {
        return match (true) {
            preg_match('/[\x00-\x1f\x7f]/', $value) === 1 => 'env_control',
            // Dotenv expands it, from the file or from the environment.
            str_contains($value, '${') => 'env_expansion',
            // Laravel's Env reads these as true, false, null and an empty string.
            preg_match('/\A(?:true|false|null|empty|\((?:true|false|null|empty)\))\z/i', $value) === 1 => 'env_keyword',
            // Laravel's Env takes off a pair of the same quotes around a value.
            preg_match('/\A(["\']).*\1\z/s', $value) === 1 => 'env_quoted',
            // Env::writeVariables writes these single-quoted, which Dotenv reads back otherwise.
            str_contains($value, '"') && (str_contains($value, '\\') || str_contains($value, "'")) => 'env_quote_mix',
            default => null,
        };
    }
}
