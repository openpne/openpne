<?php

namespace App\Support\Stream;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

/**
 * A position in a stream as one opaque `{iso8601}|{id}` string; the id is the row's primary key,
 * an integer or a UUID (docs/internals/ordering.md, "Keyset and offset").
 */
final readonly class StreamCursor
{
    public function __construct(public CarbonImmutable $at, public int|string $id) {}

    /** @throws LogicException when the row has no time: such a row has no place a cursor could name */
    public static function of(Model $row, string $column = 'created_at'): self
    {
        $at = $row->getAttribute($column);
        if ($at === null) {
            throw new LogicException(sprintf('%s #%s has no %s to take a cursor from.', $row::class, $row->getKey(), $column));
        }

        return new self($at instanceof DateTimeInterface ? CarbonImmutable::instance($at) : CarbonImmutable::parse($at), $row->getKey());
    }

    public static function tryParse(mixed $value): ?self
    {
        if (! is_string($value) || ! str_contains($value, '|')) {
            return null;
        }

        [$at, $id] = explode('|', $value, 2);
        if (! ctype_digit($id) && ! Str::isUuid($id)) {
            return null;
        }

        try {
            // Normalized to the site timezone: the query binds a DateTime by its own offset, so a
            // cursor carrying a different one would slice at the wrong wall-clock instant.
            $parsed = CarbonImmutable::createFromFormat(DateTimeInterface::ATOM, $at)->setTimezone(date_default_timezone_get());
        } catch (Throwable) {
            return null;
        }

        return new self($parsed, ctype_digit($id) ? (int) $id : $id);
    }

    public function __toString(): string
    {
        return $this->at->format(DateTimeInterface::ATOM).'|'.$this->id;
    }
}
