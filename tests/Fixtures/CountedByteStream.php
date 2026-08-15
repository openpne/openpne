<?php

namespace Tests\Fixtures;

/**
 * A stream of any declared length whose bytes are made as they are read, counting how many a reader
 * actually took. It lets a test pose a stored file far larger than the cap under test without ever
 * holding that many bytes, and then assert how few of them a bounded read pulled into memory — a
 * read that ignored its budget shows up in the count rather than only in the memory it took.
 */
class CountedByteStream
{
    private const PROTOCOL = 'openpne-counted';

    /**
     * PHP's stream layer fills its own read buffer a chunk at a time, so a read of N bytes may take
     * up to one chunk more from the wrapper beneath it. Allow this much over a budget and no more:
     * everything past it is bytes the reader asked for.
     */
    public const SLACK = 8192;

    /** @var resource */
    public $context;

    private static int $size = 0;

    private static int $consumed = 0;

    private int $position = 0;

    /** Declare what a handle from open() holds, and zero the count. */
    public static function prepare(int $size): void
    {
        if (! in_array(self::PROTOCOL, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::PROTOCOL, self::class);
        }

        self::$size = $size;
        self::$consumed = 0;
    }

    /** @return resource */
    public static function open()
    {
        return fopen(self::PROTOCOL.'://bytes', 'rb');
    }

    /** Bytes handed to readers since prepare(), across every handle. */
    public static function consumed(): int
    {
        return self::$consumed;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $this->position = 0;

        return true;
    }

    public function stream_read(int $count): string
    {
        $count = min($count, self::$size - $this->position);

        if ($count <= 0) {
            return '';
        }

        $this->position += $count;
        self::$consumed += $count;

        return str_repeat('a', $count);
    }

    public function stream_eof(): bool
    {
        return $this->position >= self::$size;
    }

    public function stream_tell(): int
    {
        return $this->position;
    }

    public function stream_seek(int $offset, int $whence = SEEK_SET): bool
    {
        return false;
    }

    public function stream_stat(): array|false
    {
        return false;
    }

    public function stream_close(): void {}
}
