/**
 * Mirrors App\Support\LinkTarget for the client: a URL on the page's own host stays in the app, any
 * other opens a new tab. The host is the page's rather than app.url, as that is the one origin the
 * router can visit (docs/internals/body-text.md, "Where a link opens").
 */

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
