import { InfiniteScroll } from '@inertiajs/react';
import { type ReactNode, useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { useT } from '@/lib/i18n';

interface LoadOlderProps {
    /** The scroll prop the page received, whose metadata carries the older cursor. */
    data: string;
    /** Refreshed by every full render of the page and by nothing else, so a replaced list starts over. */
    generation: string;
    /** What the end of the stream says; the default names posts. */
    end?: string;
    children: ReactNode;
}

// Older rows only and only on request; the URL stays the head, so a reload or a share starts there.
export function LoadOlder({ data, generation, end, children }: LoadOlderProps) {
    const t = useT();

    return (
        <InfiniteScroll
            key={generation}
            data={data}
            manual
            onlyNext
            preserveUrl
            next={({ fetch, loading, hasMore }) => <Control fetch={fetch} loading={loading} hasMore={hasMore} label={t('Load more')} busy={t('Loading…')} end={end ?? t('No older posts.')} />}
        >
            {children}
        </InfiniteScroll>
    );
}

interface ControlProps {
    fetch: () => void;
    loading: boolean;
    hasMore: boolean;
    label: string;
    busy: string;
    end: string;
}

function Control({ fetch, loading, hasMore, label, busy, end }: ControlProps) {
    const endRef = useRef<HTMLParagraphElement>(null);
    const hadMore = useRef(hasMore);
    // Only a load that reached the end has something to say; a list that fits its first page says nothing.
    const [ended, setEnded] = useState(false);

    // The button leaves with the last page; focus moves to the line that says so rather than to the body.
    useEffect(() => {
        if (hadMore.current && !hasMore) {
            setEnded(true);
        }
        hadMore.current = hasMore;
    }, [hasMore]);
    useEffect(() => {
        if (ended) {
            endRef.current?.focus();
        }
    }, [ended]);

    if (!hasMore && !ended) {
        return null;
    }

    return (
        <div className="flex justify-center py-3">
            {hasMore ? (
                // Not disabled while loading: a disabled button drops keyboard focus, so a second press is ignored instead.
                <Button type="button" variant="outline" aria-busy={loading} onClick={loading ? undefined : fetch}>
                    {loading ? busy : label}
                </Button>
            ) : (
                <p ref={endRef} tabIndex={-1} role="status" className="text-sm text-muted-foreground">
                    {end}
                </p>
            )}
        </div>
    );
}
