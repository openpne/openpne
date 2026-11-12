import { router } from '@inertiajs/react';
import type { MouseEvent } from 'react';
import { pageModules, pagePath } from '@/lib/page-modules';

/** Long enough to span a press and its click, short enough that no later visit is answered from it. */
const HOLD = '2s';

let singleUse = false;

/** Null for anything but a page object: a version mismatch answers 409 with an empty body, a proxy with HTML. */
function componentOf(data: unknown): string | null {
    try {
        const page: unknown = typeof data === 'string' ? JSON.parse(data) : data;
        const component = (page as { component?: unknown } | null)?.component;

        return typeof component === 'string' ? component : null;
    } catch {
        return null;
    }
}

function prefetch(href: string): void {
    if (!singleUse) {
        singleUse = true;
        // Until HOLD runs out Inertia answers any visit to the URL from the entry, including one
        // made after the member changed something on that page.
        router.on('navigate', (event) => router.flush(event.detail.page.url));
    }
    // A touch press is followed by a synthesized mousedown; the request it started is the one to keep.
    if (router.getPrefetching(href) ?? router.getCached(href)) {
        return;
    }
    router.prefetch(
        href,
        {
            onPrefetched: (response) => {
                const component = componentOf((response as { data?: unknown }).data);
                // Inertia caches the page data only; the page module would otherwise load after the click.
                void (component === null ? undefined : pageModules[pagePath(component)])?.();
            },
        },
        { cacheFor: HOLD },
    );
}

/**
 * Spread onto a `<Link>` whose target is a section top: the GET goes out on the press, so a page that
 * spends notification rows on GET must not carry it (docs/internals/notifications.md, "Key invariants").
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
