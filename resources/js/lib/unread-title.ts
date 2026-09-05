const CLAMP = 99;

/**
 * Inertia's title callback always receives the raw page title, never its own output, so the prefix
 * is prepended rather than maintained.
 */
export function withUnreadPrefix(title: string, count: number): string {
    return count > 0 ? `(${count > CLAMP ? `${CLAMP}+` : count}) ${title}` : title;
}
