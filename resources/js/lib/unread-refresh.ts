/**
 * The shared `unread` prop has one writer, UnreadSync. A page that has just settled something
 * countable rings this bell rather than patching the prop, which would let its guess outlive the
 * authoritative read.
 */
export const UNREAD_REFRESH_EVENT = 'openpne:unread-refresh';

/** Safe to call repeatedly; the shell coalesces by aborting its own in-flight read. */
export function requestUnreadRefresh(): void {
    window.dispatchEvent(new CustomEvent(UNREAD_REFRESH_EVENT));
}
