import { NotSupportedError, PasskeyExistsError, UserCancelledError } from '@laravel/passkeys';

// The client's defaults point at the package's own routes, which this app does not register.
export const PASSKEY_ROUTES = {
    register: { options: '/member/config/passkeys/options', submit: '/member/config/passkeys' },
    login: { options: '/passkeys/login/options', submit: '/passkeys/login' },
} as const;

/** The framework's 429 body carries this literal; every other server message arrives already translated. */
const THROTTLED = 'Too Many Attempts.';

/** The client's own placeholder for a non-Error rejection. */
const UNKNOWN = 'An unknown error occurred.';

/**
 * What a browser puts on the TypeError a failed fetch rejects with, plus the parser's complaint when
 * a signed-in tab is redirected to HTML: the client forwards both as a message, and neither is one.
 */
const NOT_A_MESSAGE = /^(Failed to fetch|Load failed|NetworkError|network error)|JSON|Unexpected token/i;

/**
 * Ceremony errors map to an i18n key; a server message (already `__()`-translated, re-wrapped by
 * the client as a bare PasskeyError) passes through as-is.
 */
export function passkeyErrorKey(error: unknown): string {
    if (error instanceof UserCancelledError) {
        return 'The passkey prompt was cancelled.';
    }
    if (error instanceof PasskeyExistsError) {
        return 'This device already holds a passkey for your account.';
    }
    if (error instanceof NotSupportedError) {
        return 'This browser does not support passkeys.';
    }
    if (error instanceof Error && error.message === THROTTLED) {
        return 'Too many attempts. Please wait a moment and try again.';
    }
    if (error instanceof Error && error.message !== '' && error.message !== UNKNOWN && !NOT_A_MESSAGE.test(error.message)) {
        return error.message;
    }

    return 'The passkey could not be used. Please try again.';
}
