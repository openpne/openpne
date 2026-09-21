import { cleanup, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, expect, test, vi } from 'vitest';
import Login from './login';
import { fakeT } from '@/lib/test-i18n';
import { renderWithProviders } from '@/lib/test-render';

vi.mock('@/lib/i18n', () => ({ useT: () => fakeT }));
vi.mock('altcha', () => ({}));
vi.mock('@laravel/passkeys/react', () => ({
    usePasskeyVerify: () => ({ verify: vi.fn(), isLoading: false, error: null, errorInstance: null, isSupported: false }),
}));
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ href, children }: { href: string; children?: ReactNode }) => <a href={href}>{children}</a>,
    router: { post: vi.fn() },
    useForm: () => ({ data: { email: '', password: '', remember: false, altcha: '' }, setData: vi.fn(), post: vi.fn(), processing: false, errors: {}, reset: vi.fn() }),
    usePage: () => ({ props: { flash: {}, locale: 'en', name: 'OpenPNE', snsLogo: { url: null, color: '#1d4ed8' }, terms: {} } }),
}));

afterEach(cleanup);

test('the email field anchors the browser passkey picker', () => {
    // The client arms conditional mediation only where an autocomplete ends in `webauthn`.
    renderWithProviders(<Login loginMessage={null} />);

    expect(screen.getByLabelText('Email').getAttribute('autocomplete')).toBe('email webauthn');
});
