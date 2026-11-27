import { cleanup, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, expect, test, vi } from 'vitest';
import RegisterComplete from './register-complete';
import { fakeT } from '@/lib/test-i18n';
import { renderWithProviders } from '@/lib/test-render';

vi.mock('@/lib/i18n', () => ({ useT: () => fakeT }));
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ href, children }: { href: string; children?: ReactNode }) => <a href={href}>{children}</a>,
    useForm: () => ({
        data: { name: '', password: '', password_confirmation: '', profile: {}, visibility: {} },
        setData: vi.fn(),
        post: vi.fn(),
        processing: false,
        errors: {},
        reset: vi.fn(),
    }),
    usePage: () => ({
        props: { token: 'x'.repeat(40), email: 'newcomer@example.com', fields: [], flash: {}, locale: 'en', name: 'OpenPNE', snsLogo: { url: null, color: '#1d4ed8' }, terms: {} },
    }),
}));

afterEach(cleanup);

test('the fixed address is the username a password manager saves the new password under', () => {
    renderWithProviders(<RegisterComplete />);

    const address = screen.getByLabelText('Mail Address') as HTMLInputElement;
    expect(address.getAttribute('autocomplete')).toBe('username');
    expect(address.value).toBe('newcomer@example.com');
    expect(address.readOnly).toBe(true);
    expect(screen.getByLabelText('Password').getAttribute('autocomplete')).toBe('new-password');
});
