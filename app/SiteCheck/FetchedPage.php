<?php

declare(strict_types=1);

namespace App\SiteCheck;

final class FetchedPage
{
    /** @param ?int $status null when nothing answered */
    public function __construct(
        public readonly ?int $status,
        public readonly string $body,
        public readonly ?string $location = null,
        public readonly ?string $error = null,
    ) {}

    public function answered(string $token): bool
    {
        return $this->status === 200 && trim($this->body) === $token;
    }

    /** The transport's reason without libcurl's pointer to its error table. */
    public function reason(): string
    {
        return trim((string) preg_replace('/\s*\(see https?:[^)]*\).*$/s', '', (string) $this->error));
    }
}
