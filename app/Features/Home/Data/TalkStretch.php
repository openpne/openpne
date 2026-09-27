<?php

declare(strict_types=1);

namespace App\Features\Home\Data;

use App\Models\Group;
use App\Models\GroupMessage;

/**
 * One room's stretch as a month draws it. `picture` is what the per-file gate let through and
 * nothing else says a picture is there (docs/internals/home-issues.md, "The month page").
 */
final readonly class TalkStretch
{
    /** @param  array<string, mixed>|null  $picture */
    public function __construct(
        public Group $group,
        public int $count,
        public GroupMessage $last,
        public ?array $picture = null,
    ) {}

    public function pictured(?array $picture): self
    {
        return new self($this->group, $this->count, $this->last, $picture);
    }
}
