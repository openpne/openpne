<?php

namespace App\Features\GroupTalk;

use App\Models\GroupMessage;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Throwable;

/**
 * A position in a group's talk: the (created_at, id) tuple that orders it. Serialized as one opaque
 * `{iso8601}|{id}` string so the client only ever echoes a cursor the server handed it, and a page
 * boundary keeps working after the message it was taken from is deleted (the tuple is a position,
 * not a row reference).
 *
 * This is pagination only. The unread read cursor is a different thing with a different rule — the
 * client names a message id there and the server resolves the tuple itself — because that one
 * decides what counts as read.
 */
final readonly class GroupTalkCursor
{
    public function __construct(public CarbonImmutable $at, public int $id) {}

    public static function of(GroupMessage $message): self
    {
        return new self(CarbonImmutable::instance($message->created_at), $message->getKey());
    }

    /** Parse a cursor the client echoed back, or null when it is absent or malformed. */
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
