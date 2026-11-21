<?php

declare(strict_types=1);

namespace App\SiteCheck;

/** One line of openpne:check; `id` is what a caller tests, `message` a key of lang/{locale}/console.php under `check.`. */
final class Finding
{
    /** @param array<string, string|int> $replace */
    public function __construct(
        public readonly string $id,
        public readonly Verdict $verdict,
        public readonly string $message,
        public readonly array $replace = [],
    ) {}

    /** @param array<string, string|int> $replace */
    public static function ok(string $id, string $message, array $replace = []): self
    {
        return new self($id, Verdict::Ok, $message, $replace);
    }

    /** @param array<string, string|int> $replace */
    public static function fail(string $id, string $message, array $replace = []): self
    {
        return new self($id, Verdict::Fail, $message, $replace);
    }

    /** @param array<string, string|int> $replace */
    public static function warn(string $id, string $message, array $replace = []): self
    {
        return new self($id, Verdict::Warn, $message, $replace);
    }

    /** @param array<string, string|int> $replace */
    public static function unknown(string $id, string $message, array $replace = []): self
    {
        return new self($id, Verdict::Unknown, $message, $replace);
    }
}
