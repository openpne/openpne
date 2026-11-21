<?php

declare(strict_types=1);

namespace App\Outbound;

final class OutboundTimeout
{
    /**
     * A value below 1 falls back to $fallback, since zero would lift a timeout or spend a deadline before the first request (docs/internals/outbound-http.md, "Size and time limits").
     * The default rather than a bare 1 second, so a mistyped value behaves like an unset one.
     */
    public static function seconds(string $key, int $fallback): int
    {
        $value = (int) config("openpne.outbound.{$key}");

        return $value > 0 ? $value : $fallback;
    }
}
