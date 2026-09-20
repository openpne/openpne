import { NotSupportedError, PasskeyError, PasskeyExistsError, UserCancelledError } from '@laravel/passkeys';

// The client's defaults point at the package's own routes, which this app does not register.
export const PASSKEY_ROUTES = {
    register: { options: '/member/config/passkeys/options', submit: '/member/config/passkeys' },
    login: { options: '/passkeys/login/options', submit: '/passkeys/login' },
} as const;

/** The i18n key for a ceremony failure; the server's own 422 message is passed through as-is. */
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
    if (error instanceof PasskeyError && /status 429\b/.test(error.message)) {
        return 'Too many attempts. Please wait a moment and try again.';
    }
    if (error instanceof PasskeyError && /status 403\b/.test(error.message)) {
        return 'Some time has passed since you confirmed your password. Please confirm it again.';
    }
    if (error instanceof Error && error.message !== '') {
        return error.message;
    }

    return 'The passkey could not be used. Please try again.';
}
