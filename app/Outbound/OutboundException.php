<?php

declare(strict_types=1);

namespace App\Outbound;

use RuntimeException;

/**
 * A fetch did not produce a usable response.
 *
 * The reason separates "the guard refused this destination" from "the network or the far end
 * failed", which callers log differently: the first is a member pasting a URL we will never fetch
 * (and never should retry), the second is transient.
 */
final class OutboundException extends RuntimeException
{
    public const BLOCKED = 'blocked';

    public const FAILED = 'failed';

    private function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }

    /** The destination is not one this app may connect to. Retrying cannot help. */
    public static function blocked(string $message): self
    {
        return new self($message, self::BLOCKED);
    }

    /** The request was permitted but did not complete. */
    public static function failed(string $message): self
    {
        return new self($message, self::FAILED);
    }

    public function isBlocked(): bool
    {
        return $this->reason === self::BLOCKED;
    }
}
