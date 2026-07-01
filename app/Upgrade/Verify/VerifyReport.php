<?php

namespace App\Upgrade\Verify;

use JsonSerializable;

/**
 * The verify-upgrade verdict: an ordered list of named checks, each pass or fail with a detail line.
 * JsonSerializable so the command can emit it under --json.
 */
final class VerifyReport implements JsonSerializable
{
    /** @var list<array{name: string, status: string, detail: string}> */
    private array $checks = [];

    public function pass(string $name, string $detail = ''): void
    {
        $this->checks[] = ['name' => $name, 'status' => 'pass', 'detail' => $detail];
    }

    public function fail(string $name, string $detail = ''): void
    {
        $this->checks[] = ['name' => $name, 'status' => 'fail', 'detail' => $detail];
    }

    /** @return list<array{name: string, status: string, detail: string}> */
    public function checks(): array
    {
        return $this->checks;
    }

    public function failed(): bool
    {
        foreach ($this->checks as $check) {
            if ($check['status'] === 'fail') {
                return true;
            }
        }

        return false;
    }

    public function passedCount(): int
    {
        return count(array_filter($this->checks, static fn (array $c): bool => $c['status'] === 'pass'));
    }

    /** @return array{passed: int, total: int, checks: list<array{name: string, status: string, detail: string}>} */
    public function jsonSerialize(): array
    {
        return [
            'passed' => $this->passedCount(),
            'total' => count($this->checks),
            'checks' => $this->checks,
        ];
    }
}
