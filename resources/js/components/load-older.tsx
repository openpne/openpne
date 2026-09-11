import { InfiniteScroll } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { useT } from '@/lib/i18n';

interface LoadOlderProps {
    /** The scroll prop the page received, whose metadata carries the older cursor. */
    data: string;
    children: ReactNode;
}

// Older rows only and only on request; the URL stays the head, so a reload or a share starts there.
export function LoadOlder({ data, children }: LoadOlderProps) {
    const t = useT();

    return (
        <InfiniteScroll
            data={data}
            manual
            onlyNext
            preserveUrl
            next={({ fetch, loading, hasMore }) =>
                hasMore ? (
                    <div className="flex justify-center py-3">
                        {/* Not disabled while loading: a disabled button drops keyboard focus, so a second press is ignored instead. */}
                        <Button type="button" variant="outline" aria-busy={loading} onClick={loading ? undefined : fetch}>
                            {loading ? t('Loading…') : t('Load more')}
                        </Button>
                    </div>
                ) : null
            }
        >
            {children}
        </InfiniteScroll>
    );
}
