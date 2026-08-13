/**
 * How a page tells the shell that a badge it owns has just gone stale.
 *
 * The shared `unread` prop has one writer — UnreadSync, which re-reads the counts on its own clock.
 * A page that settles something countable with the server (reading a talk, say) knows the number has
 * moved a minute before that clock does, but patching the prop itself would make two writers of one
 * value and let a page's guess outlive the authoritative read. So it rings this bell instead and the
 * shell does the reading.
 */
export const UNREAD_REFRESH_EVENT = 'openpne:unread-refresh';

/** Ring it. Safe to call repeatedly; the shell coalesces by aborting its own in-flight read. */
export function requestUnreadRefresh(): void {
    window.dispatchEvent(new CustomEvent(UNREAD_REFRESH_EVENT));
}
