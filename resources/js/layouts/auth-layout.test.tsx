import { cleanup, fireEvent, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, expect, test, vi } from 'vitest';
import { AuthLayout } from './auth-layout';
import { fakeT } from '@/lib/test-i18n';
import { renderWithProviders } from '@/lib/test-render';

vi.mock('@/lib/i18n', () => ({ useT: () => fakeT }));

const inertia = vi.hoisted(() => ({ post: vi.fn() }));

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ component: 'auth/register', url: '/register/secret', props: { locale: 'ja', name: 'Site', snsLogo: { url: null } } }),
    Link: ({ href, children }: { href: string; children?: ReactNode }) => <a href={href}>{children}</a>,
    Head: () => null,
    router: { post: inertia.post },
}));

const reload = vi.fn();

beforeEach(() => {
    vi.stubGlobal('location', { ...window.location, reload });
});

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
    reload.mockReset();
    inertia.post.mockReset();
});

// The page reloads itself: a secret-bearing page is never the session's previous URL to go back to.
test('a language switch reloads the page it is on', async () => {
    const fetch = vi.fn().mockResolvedValue(new Response(null, { status: 204 }));
    vi.stubGlobal('fetch', fetch);
    document.cookie = 'XSRF-TOKEN=xsrf%3D';
    renderWithProviders(<AuthLayout title="Register">form</AuthLayout>);

    fireEvent.click(screen.getByRole('button', { name: 'English' }));

    await vi.waitFor(() => expect(reload).toHaveBeenCalledOnce());
    expect(fetch).toHaveBeenCalledWith('/locale', expect.objectContaining({
            method: 'POST',
            headers: expect.objectContaining({ 'X-XSRF-TOKEN': 'xsrf=', 'Content-Type': 'application/json', Accept: 'application/json' }),
            body: JSON.stringify({ locale: 'en' }),
        }));
    expect(inertia.post).not.toHaveBeenCalled();
});

test.each([
    ['refused', () => Promise.resolve(new Response(null, { status: 419 }))],
    ['unreachable', () => Promise.reject(new TypeError('offline'))],
])('a %s switch falls back to the Inertia post', async (_, answer) => {
    vi.stubGlobal('fetch', vi.fn(answer));
    renderWithProviders(<AuthLayout title="Register">form</AuthLayout>);

    fireEvent.click(screen.getByRole('button', { name: 'English' }));

    await vi.waitFor(() => expect(inertia.post).toHaveBeenCalledWith('/locale', { locale: 'en' }));
    expect(reload).not.toHaveBeenCalled();
});
