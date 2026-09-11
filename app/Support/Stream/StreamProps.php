<?php

namespace App\Support\Stream;

use Inertia\Inertia;
use Inertia\ScrollMetadata;
use Inertia\ScrollProp;

final class StreamProps
{
    /**
     * A value only a full page render refreshes: keying the client's stream component on it remounts
     * the component, and with it Inertia's stored next cursor, whenever the rows were replaced rather
     * than merged (docs/internals/ordering.md, "Keyset and offset").
     */
    public static function generation(): string
    {
        return bin2hex(random_bytes(4));
    }

    /**
     * The cursor travels in the scroll metadata only, which is what InfiniteScroll reads; the payload
     * carries rows and nothing else.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  StreamPage<TModel>  $page
     * @param  callable(TModel): mixed  $row
     * @return ScrollProp<array{data: list<mixed>}>
     */
    public static function scroll(StreamPage $page, callable $row, ?StreamCursor $current): ScrollProp
    {
        return Inertia::scroll(
            fn () => $page->payload($row),
            metadata: new ScrollMetadata(StreamRequest::PARAM, null, $page->olderCursor()?->__toString(), $current?->__toString()),
        );
    }
}
