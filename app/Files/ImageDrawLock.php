<?php

namespace App\Files;

use Closure;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use RuntimeException;
use Throwable;

/**
 * One request's turn at drawing a cache key: an OS flock on one of 256 fixed files per set, waited for
 * until ten seconds after the request's first miss, every nested wait spending the same deadline
 * (docs/internals/images.md, "Drawing once"). A caller holds no write transaction, the holder's own
 * writes otherwise waiting on it.
 */
final class ImageDrawLock
{
    private const WAIT_US = 10_000_000;

    private const NAP_US = 100_000;

    private const FIND_EVERY = 5;

    private const NOTE = '/\A[0-9a-f]{56}\z/';

    private readonly int $deadline;

    private int $slept = 0;

    public function __construct(private readonly string $directory)
    {
        $this->deadline = hrtime(true) + self::WAIT_US * 1000;
    }

    /**
     * $find reads the cache and is null on a miss; $placed, run as the lock is let go, says whether
     * the cache now answers for $key, the waiters otherwise being sent to draw for themselves.
     *
     * @param  Closure(): ?string  $find
     * @param  Closure(): string  $draw
     * @param  Closure(): bool  $placed
     *
     * @throws ImageDrawBusyException
     */
    public function run(string $set, string $key, Closure $find, Closure $draw, Closure $placed): string
    {
        $path = $this->directory.'/'.$set.'-'.substr(sha1($key), 0, 2).'.lock';
        $handle = $this->open($path);

        if ($handle === null) {
            return $draw();
        }

        if (@flock($handle, LOCK_EX | LOCK_NB, $wouldBlock)) {
            try {
                return $find() ?? $draw();
            } finally {
                $this->release($handle, $key, $placed);
            }
        }

        if (! $wouldBlock) {
            fclose($handle);
            report(new RuntimeException("The image draw lock [{$path}] refused flock; drawing without it."));

            return $draw();
        }

        try {
            $found = $this->wait($handle, $key, $find);
        } finally {
            fclose($handle);
        }

        return $found ?? $draw();
    }

    /**
     * The hit once the holder is gone, or null for the caller to draw without the lock. A waiter never
     * takes the exclusive lock, and holds the shared one only for the instant of the test.
     *
     * @param  resource  $handle
     * @param  Closure(): ?string  $find
     *
     * @throws ImageDrawBusyException
     */
    private function wait($handle, string $key, Closure $find): ?string
    {
        $base = $this->read($handle);
        $mine = sha1($key);

        for ($nap = 1; ; $nap++) {
            $this->nap();

            if ($this->remaining() <= 0) {
                return $find() ?? $this->busy($key);
            }

            // The holder may be drawing another key that shares this file, after ours was placed.
            if ($nap % self::FIND_EVERY === 0) {
                if (($found = $find()) !== null) {
                    return $found;
                }

                if ($this->remaining() <= 0) {
                    $this->busy($key);
                }
            }

            if (@flock($handle, LOCK_SH | LOCK_NB)) {
                @flock($handle, LOCK_UN);

                return $find();
            }

            $note = $this->read($handle);

            // Best effort: a note written before $base was read, or overwritten by another key, is missed and the deadline applies.
            if ($note !== $base && str_starts_with($note, $mine) && preg_match(self::NOTE, $note) === 1) {
                return $find();
            }
        }
    }

    /**
     * Runs inside the holder's finally, so neither the check nor the note may throw over the
     * exception in flight; a check that fails writes the note.
     *
     * @param  resource  $handle
     * @param  Closure(): bool  $placed
     */
    private function release($handle, string $key, Closure $placed): void
    {
        try {
            try {
                $note = ! $placed();
            } catch (Throwable) {
                $note = true;
            }

            // Overwritten in place at a fixed length and never truncated, so a full disk can still take it.
            if ($note) {
                rewind($handle);
                @fwrite($handle, sha1($key).bin2hex(random_bytes(8)));
                @fflush($handle);
            }
        } catch (Throwable) {
            // The waiters fall back to the deadline.
        } finally {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @return resource|null */
    private function open(string $path)
    {
        if (! is_dir($this->directory) && ! @mkdir($this->directory, 0777, true) && ! is_dir($this->directory)) {
            report(new RuntimeException("The image draw lock directory [{$this->directory}] cannot be made; drawing without it."));

            return null;
        }

        $handle = @fopen($path, 'c+');

        if ($handle === false) {
            report(new RuntimeException("The image draw lock [{$path}] cannot be opened; drawing without it."));

            return null;
        }

        return $handle;
    }

    /**
     * rewind() and not stream_get_contents()'s offset, which skips the seek when the position already
     * matches and so keeps an end of file hit on an empty file.
     *
     * @param  resource  $handle
     */
    private function read($handle): string
    {
        $content = @rewind($handle) ? @stream_get_contents($handle, 57) : false;

        return $content === false ? '' : $content;
    }

    private function nap(): void
    {
        $us = min(self::NAP_US, $this->remaining());

        if ($us > 0) {
            Sleep::usleep($us);
            $this->slept += $us;
        }
    }

    /** Both clocks: the monotonic one in production, the slept total under a faked Sleep. */
    private function remaining(): int
    {
        return min(intdiv($this->deadline - hrtime(true), 1000), self::WAIT_US - $this->slept);
    }

    /** @throws ImageDrawBusyException */
    private function busy(string $key): never
    {
        Log::warning('An image draw outlasted the wait for it.', ['key' => $key]);

        throw new ImageDrawBusyException("The draw of [{$key}] outlasted this request's wait for it.");
    }
}
