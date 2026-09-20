import { act, cleanup, fireEvent, screen } from '@testing-library/react';
import { afterEach, expect, test, vi } from 'vitest';
import { PasskeySignIn } from './passkey-sign-in';
import { fakeT } from '@/lib/test-i18n';
import { renderWithProviders } from '@/lib/test-render';

vi.mock('@/lib/i18n', () => ({ useT: () => fakeT }));

const hook = vi.hoisted(() => ({
    isSupported: true,
    verify: vi.fn(async () => {}),
    options: undefined as undefined | { onError?: (e: Error) => void; onSuccess?: (r: { redirect?: string }) => void; autofill?: boolean; remember?: () => boolean },
}));

vi.mock('@laravel/passkeys/react', () => ({
    usePasskeyVerify: (options: typeof hook.options) => {
        hook.options = options;
        return { verify: hook.verify, isLoading: false, error: null, errorInstance: null, isSupported: hook.isSupported };
    },
}));

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
    hook.isSupported = true;
    hook.verify.mockClear();
});

test('renders nothing where WebAuthn is unsupported', () => {
    hook.isSupported = false;
    renderWithProviders(<PasskeySignIn remember={() => false} />);

    expect(screen.queryByRole('button')).toBeNull();
});

test('arms autofill with the remember box and runs the ceremony from the button', async () => {
    const remember = () => true;
    renderWithProviders(<PasskeySignIn remember={remember} />);

    expect(hook.options?.autofill).toBe(true);
    expect(hook.options?.remember).toBe(remember);

    fireEvent.click(screen.getByRole('button', { name: 'Sign in with a passkey' }));
    await act(() => Promise.resolve());

    expect(hook.verify).toHaveBeenCalledTimes(1);
});

test('a verified passkey sends the member where the server said', async () => {
    const assign = vi.fn();
    vi.stubGlobal('location', { assign });
    renderWithProviders(<PasskeySignIn remember={() => false} />);

    act(() => hook.options?.onSuccess?.({ redirect: 'https://example.test/home' }));
    expect(assign).toHaveBeenCalledWith('https://example.test/home');

    act(() => hook.options?.onSuccess?.({}));
    expect(assign).toHaveBeenLastCalledWith('/');
});

test('a refusal that follows the member picking a passkey is shown', () => {
    renderWithProviders(<PasskeySignIn remember={() => false} />);
    // Opening the browser's picker starts with a gesture on the page.
    fireEvent.pointerDown(document.body);

    act(() => hook.options?.onError?.(new Error('Unable to sign in with this account.')));

    expect(screen.getByRole('alert').textContent).toBe('Unable to sign in with this account.');
});

test('a failure while arming the picker stays quiet', () => {
    renderWithProviders(<PasskeySignIn remember={() => false} />);

    act(() => hook.options?.onError?.(new Error('Failed to fetch')));

    expect(screen.queryByRole('alert')).toBeNull();
});

test('a refusal message clears when the button is tried again', async () => {
    renderWithProviders(<PasskeySignIn remember={() => false} />);
    fireEvent.pointerDown(document.body);
    act(() => hook.options?.onError?.(new Error('Too Many Attempts.')));
    expect(screen.getByRole('alert').textContent).toBe('Too many attempts. Please wait a moment and try again.');

    fireEvent.click(screen.getByRole('button', { name: 'Sign in with a passkey' }));
    await act(() => Promise.resolve());

    expect(screen.queryByRole('alert')).toBeNull();
});
