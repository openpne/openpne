/**
 * The absolute URL $value names when its scheme is one of $protocols, else null; a relative reference
 * is null. CodeQL takes the return value as XSS-safe (.github/codeql/extensions), so an href or src
 * built from user input goes through here.
 */
export function safeUrl(value: string, protocols: readonly ('http:' | 'https:' | 'blob:')[] = ['http:', 'https:']): string | null {
    let url: URL;
    try {
        url = new URL(value);
    } catch {
        return null;
    }

    return (protocols as readonly string[]).includes(url.protocol) ? url.href : null;
}
