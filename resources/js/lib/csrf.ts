/**
 * Laravel sets a URL-encoded `XSRF-TOKEN` cookie and `VerifyCsrfToken` accepts its decoded value in
 * an `X-XSRF-TOKEN` header. Inertia handles its own, and there is no axios in the stack to do it for
 * the raw `fetch` calls.
 */

/** Takes the cookie string rather than reading `document.cookie`, so it is unit-testable. */
export function readCookie(cookies: string, name: string): string | null {
    for (const part of cookies.split(';')) {
        const trimmed = part.trim();
        const eq = trimmed.indexOf('=');
        if (eq === -1) {
            continue;
        }
        if (trimmed.slice(0, eq) === name) {
            return trimmed.slice(eq + 1);
        }
    }

    return null;
}

/** The `X-XSRF-TOKEN` header for a fetch, or `{}` when the cookie is absent. */
export function xsrfHeader(): Record<string, string> {
    const raw = readCookie(document.cookie, 'XSRF-TOKEN');

    return raw ? { 'X-XSRF-TOKEN': decodeURIComponent(raw) } : {};
}
