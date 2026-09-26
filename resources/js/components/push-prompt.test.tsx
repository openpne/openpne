import { act, cleanup, fireEvent, screen } from '@testing-library/react';
import { afterEach, expect, test, vi } from 'vitest';
import { PushPrompt } from './push-prompt';
import { fakeT } from '@/lib/test-i18n';
import { renderWithProviders } from '@/lib/test-render';

vi.mock('@/lib/i18n', () => ({ useT: () => fakeT }));

const inertia = vi.hoisted(() => ({ page: { props: { push: null as { vapidPublicKey: string } | null } } }));
vi.mock('@inertiajs/react', () => ({ usePage: () => inertia.page }));

const pushLib = vi.hoisted(() => ({
    permission: 'default' as 'unsupported' | 'default' | 'granted' | 'denied',
    iosNotInstalled: false,
    subscribeThisDevice: vi.fn<(key: string) => Promise<'subscribed' | 'denied' | 'error'>>(),
}));
vi.mock('@/lib/push', () => ({
    permissionState: () => pushLib.permission,
    isIosNotInstalled: () => pushLib.iosNotInstalled,
    subscribeThisDevice: pushLib.subscribeThisDevice,
}));

const STORAGE_KEY = 'openpne-push-prompt';

const enable = () => screen.queryByRole('button', { name: 'Enable' });

const configured = () => {
    inertia.page.props.push = { vapidPublicKey: 'vapid-key' };
};

afterEach(() => {
    cleanup();
    vi.clearAllMocks();
    vi.unstubAllGlobals();
    window.localStorage.clear();
    inertia.page.props.push = null;
    pushLib.permission = 'default';
    pushLib.iosNotInstalled = false;
});

test('the prompt stands only while push is configured, permission is still to be asked, and it has not been dismissed', () => {
    renderWithProviders(<PushPrompt />);
    expect(enable()).toBeNull();
    cleanup();

    configured();
    renderWithProviders(<PushPrompt />);
    expect(enable()).toBeTruthy();
    cleanup();

    window.localStorage.setItem(STORAGE_KEY, '1');
    renderWithProviders(<PushPrompt />);
    expect(enable()).toBeNull();
});

test.each([['granted'], ['denied'], ['unsupported']] as const)('once permission is %s there is nothing to ask', (state) => {
    pushLib.permission = state;
    configured();
    renderWithProviders(<PushPrompt />);

    expect(enable()).toBeNull();
});

test.each([['subscribed'], ['denied']] as const)('enabling subscribes this device with the site key; %s puts the prompt away for this load only', async (outcome) => {
    pushLib.subscribeThisDevice.mockResolvedValue(outcome);
    configured();
    renderWithProviders(<PushPrompt />);

    await act(async () => {
        fireEvent.click(enable()!);
    });

    expect(pushLib.subscribeThisDevice).toHaveBeenCalledWith('vapid-key');
    expect(enable()).toBeNull();
    expect(window.localStorage.getItem(STORAGE_KEY)).toBeNull();
});

test('a failed subscription says so and keeps the prompt for another try', async () => {
    pushLib.subscribeThisDevice.mockResolvedValue('error');
    configured();
    renderWithProviders(<PushPrompt />);

    await act(async () => {
        fireEvent.click(enable()!);
    });
    expect(screen.getByText('Something went wrong. Please try again.')).toBeTruthy();
    expect(enable()).toBeTruthy();

    // The next try clears the message while it is still out.
    let settle: (outcome: 'subscribed') => void = () => {};
    pushLib.subscribeThisDevice.mockImplementation(() => new Promise((resolve) => (settle = resolve)));
    fireEvent.click(enable()!);
    expect(screen.queryByText('Something went wrong. Please try again.')).toBeNull();
    expect(enable()).toBeTruthy();

    await act(async () => {
        settle('subscribed');
    });
    expect(enable()).toBeNull();
});

test('dismissing is remembered across loads; a locked storage still puts it away for now', () => {
    configured();
    renderWithProviders(<PushPrompt />);
    fireEvent.click(screen.getByRole('button', { name: 'Dismiss' }));
    expect(enable()).toBeNull();
    expect(window.localStorage.getItem(STORAGE_KEY)).toBe('1');
    cleanup();
    window.localStorage.clear();

    const locked = new Proxy(
        {},
        {
            get: () => () => {
                throw new Error('locked');
            },
        },
    );
    vi.stubGlobal('localStorage', locked);
    renderWithProviders(<PushPrompt />);
    expect(enable()).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Dismiss' }));
    expect(enable()).toBeNull();
});

test('on an iPhone or iPad that is not installed, the prompt points at the Home Screen instead of offering a button', () => {
    pushLib.iosNotInstalled = true;
    configured();
    renderWithProviders(<PushPrompt />);

    expect(screen.getByText('To get push notifications on iPhone or iPad, add this site to your Home Screen first.')).toBeTruthy();
    expect(enable()).toBeNull();
});
