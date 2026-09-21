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
    if (error instanceof Error && error.message !== '' && error.message !== UNKNOWN) {
        return error.message;
    }

    return 'The passkey could not be used. Please try again.';
}
