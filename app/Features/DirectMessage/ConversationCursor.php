<?php

namespace App\Features\DirectMessage;

use App\Models\DirectMessage;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Throwable;

/**
 * A position in a conversation: the (created_at, id) tuple that orders it, as one opaque
 * `{iso8601}|{id}` string. The client only ever echoes a cursor the server handed it, so the tuple
 * encoding stays on this side and a boundary keeps working after the message it was taken from is
 * gone — a cursor names a position, not a row.
 */
final readonly class ConversationCursor
{
    public function __construct(public CarbonImmutable $at, public int $id) {}

    public static function of(DirectMessage $message): self
    {
        return new self(CarbonImmutable::instance($message->created_at), (int) $message->getKey());
    }

    /** Parse a cursor the client echoed back, or null when it is absent or malformed — an array query (`?before[]=`) included. */
    public static function tryParse(mixed $value): ?self
    {
        if (! is_string($value) || ! str_contains($value, '|')) {
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
