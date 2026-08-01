/** Counts above this render as `99+`, matching the badge pill (see UnreadPill). */
const CLAMP = 99;

/**
 * The document title's unread-notification prefix. Applied inside Inertia's title callback
 * (app.tsx), which always receives the raw page title — never its own output — so the prefix is
 * prepended, not maintained: a title that itself starts with "(2026) " keeps it.
 */
export function withUnreadPrefix(title: string, count: number): string {
    return count > 0 ? `(${count > CLAMP ? `${CLAMP}+` : count}) ${title}` : title;
}
