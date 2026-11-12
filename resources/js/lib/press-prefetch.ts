import { router } from '@inertiajs/react';
import type { MouseEvent } from 'react';
import { pageModules, pagePath } from '@/lib/page-modules';

/** Long enough to span a press and its click, short enough that no later visit is answered from it. */
const HOLD = '2s';

let flushing = false;

function prefetch(href: string): void {
    if (!flushing) {
        flushing = true;
        // Inertia never expires an entry whose response arrived after the click that used it, and
        // would answer every later visit to that URL from it.
        router.on('navigate', () => router.flushAll());
    }
    // A touch press is followed by a synthesized mousedown; the request it started is the one to keep.
    if (router.getPrefetching(href) ?? router.getCached(href)) {
        return;
    }
    router.prefetch(
        href,
        {
            onPrefetched: (response) => {
                const raw = (response as { data?: unknown }).data;
                const page = (typeof raw === 'string' ? JSON.parse(raw) : raw) as { component?: string } | undefined;
                // Inertia caches the page data only; the page module would otherwise load after the click.
                void (page?.component ? pageModules[pagePath(page.component)] : undefined)?.();
            },
        },
        { cacheFor: HOLD },
    );
}

/**
 * Spread onto a `<Link>` whose target is a section top: the GET goes out on the press, so only a
 * destination that writes nothing on GET may carry it (docs/internals/notifications.md, "Key invariants").
 */
export function pressPrefetch(href: string) {
    return {
        onTouchStart: () => prefetch(href),
        onMouseDown: (event: MouseEvent) => {
            if (event.button === 0 && !event.metaKey && !event.ctrlKey && !event.shiftKey && !event.altKey) {
                prefetch(href);
            }
        },
    };
}
