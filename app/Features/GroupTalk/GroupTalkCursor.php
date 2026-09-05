<?php

namespace App\Features\GroupTalk;

use App\Models\GroupMessage;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Throwable;

/**
 * The pagination position, not the unread read cursor: this one is echoed back by the client and
 * decides only which page is read.
 */
final readonly class GroupTalkCursor
{
    public function __construct(public CarbonImmutable $at, public int $id) {}

    public static function of(GroupMessage $message): self
    {
        return new self(CarbonImmutable::instance($message->created_at), $message->getKey());
    }

    public static function tryParse(?string $value): ?self
    {
        if ($value === null || ! str_contains($value, '|')) {
            return null;
        }

        [$at, $id] = explode('|', $value, 2);
        if (! ctype_digit($id)) {
            return null;
        }

        try {
            // Normalized to the site timezone: the query binds a DateTime by its own offset, so a
            // cursor carrying a different one would slice at the wrong wall-clock instant.
            return new self(CarbonImmutable::parse($at)->setTimezone(date_default_timezone_get()), (int) $id);
        } catch (Throwable) {
            return null;
        }
    }

    public function __toString(): string
    {
        return $this->at->format(DateTimeInterface::ATOM).'|'.$this->id;
    }
}
