/**
 * Laravel CSRF for the app's few raw `fetch` calls (Inertia handles its own). The framework sets an
 * `XSRF-TOKEN` cookie (URL-encoded); VerifyCsrfToken accepts its decoded value in an `X-XSRF-TOKEN`
 * header. There is no axios in the stack to do this automatically.
 */

/** Reads one cookie's raw value from a `document.cookie` string. Pure, so it is unit-testable. */
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
