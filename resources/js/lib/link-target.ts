/**
 * Mirrors App\Support\LinkTarget for the client: a URL on the page's own host stays in the app, any
 * other opens a new tab. The host is the page's rather than app.url, as that is the one origin the
 * router can visit (docs/internals/body-text.md, "Where a link opens").
 */

/**
 * The first path segments under which this site answers with bytes rather than a page; kept equal
 * to the routes' by BytesRouteSegmentsParityTest.
 */
export const BYTES_SEGMENTS = ['app-icon', 'banner', 'cache', 'f', 'file', 'linkCard'] as const;

/**
 * Whether $path, as inAppHref returns it, is served as bytes, which the router must not fetch to find
 * out. One leading slash exactly: a second one would make the path a host for the browser.
 */
export function isBytesHref(path: string): boolean {
    const segment = /^\/([^/?#]+)/.exec(path)?.[1] ?? '';

    return (BYTES_SEGMENTS as readonly string[]).includes(segment);
}

/** The path the router visits for $href, or null when it leaves this site. */
export function inAppHref(href: string, host: string): string | null {
    let url: URL;
    try {
        url = new URL(href);
    } catch {
        return null;
    }

    if ((url.protocol !== 'http:' && url.protocol !== 'https:') || url.host !== host.toLowerCase()) {
        return null;
    }

    return `${url.pathname}${url.search}${url.hash}`;
}

/** Whether a click on a link should be handled by the router rather than left to the browser. */
export function isPlainClick(event: { defaultPrevented: boolean; button: number; metaKey: boolean; ctrlKey: boolean; shiftKey: boolean; altKey: boolean }): boolean {
    return !event.defaultPrevented && event.button === 0 && !event.metaKey && !event.ctrlKey && !event.shiftKey && !event.altKey;
}
