/**
 * One answer for the menu's item and its gate. Feature-detected because the clipboard is a
 * secure-context API — a site served over plain http has no `navigator.clipboard` at all, and an
 * offer that silently does nothing is worse than none.
 */
export function canCopyText(body: string): boolean {
    return body.trim() !== '' && typeof navigator.clipboard?.writeText === 'function';
}

/** Whether a link to a message can be offered — the clipboard alone decides; every message has an address. */
export function canCopyLink(): boolean {
    return typeof navigator.clipboard?.writeText === 'function';
}

/**
 * Built from the page itself, so a sub-directory install needs no telling. Any other query is
 * dropped: `context` names this visit's position, not the message's.
 */
export function messageLink(id: number): string {
    const url = new URL(window.location.href);
    url.search = '';
    url.hash = '';
    url.searchParams.set('m', String(id));

    return url.toString();
}
