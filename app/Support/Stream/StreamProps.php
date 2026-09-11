<?php

namespace App\Support\Stream;

use Inertia\Inertia;
use Inertia\ScrollMetadata;
use Inertia\ScrollProp;

final class StreamProps
{
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
