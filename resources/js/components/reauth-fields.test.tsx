import { act, cleanup, fireEvent, screen } from '@testing-library/react';
import { useState } from 'react';
import { afterEach, expect, test, vi } from 'vitest';
import { REAUTH_DATA, ReauthFields, type ReauthData } from './reauth-fields';
import { fakeT } from '@/lib/test-i18n';
import { renderWithProviders } from '@/lib/test-render';

vi.mock('@/lib/i18n', () => ({ useT: () => fakeT }));

const page = vi.hoisted(() => ({ user: { hasTwoFactor: false, hasPasskey: true } }));
vi.mock('@inertiajs/react', () => ({ usePage: () => ({ props: { auth: { user: page.user } } }) }));

const passkeys = vi.hoisted(() => ({ supported: true, verify: vi.fn<(options: { routes: { options: string; submit: string } }) => Promise<object>>(async () => ({})) }));
vi.mock('@laravel/passkeys', async (original) => ({
    ...(await original<typeof import('@laravel/passkeys')>()),
    Passkeys: { isSupported: () => passkeys.supported, verify: passkeys.verify },
}));

afterEach(() => {
    cleanup();
    page.user = { hasTwoFactor: false, hasPasskey: true };
    passkeys.supported = true;
    passkeys.verify.mockReset();
    passkeys.verify.mockResolvedValue({});
});

function Form({ onSubmit }: { onSubmit: (data: ReauthData) => void }) {
    const [data, setData] = useState<ReauthData>({ ...REAUTH_DATA, current_password: 'typed' });

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                onSubmit(data);
            }}
        >
            <ReauthFields
                idPrefix="t"
                purpose="withdrawal"
                data={data}
                errors={{}}
                onChange={(field, value) => setData((d) => ({ ...d, [field]: value }))}
                onResetSecondFactor={() => {}}
            />
        </form>
    );
}

const button = () => screen.queryByRole('button', { name: 'Confirm with a passkey instead' });

test('the passkey button shows only for a member who holds one, on a browser that can use it', () => {
    page.user = { hasTwoFactor: false, hasPasskey: false };
    const first = renderWithProviders(<Form onSubmit={() => {}} />);
    expect(button()).toBeNull();
    first.unmount();

    page.user = { hasTwoFactor: false, hasPasskey: true };
    passkeys.supported = false;
    const second = renderWithProviders(<Form onSubmit={() => {}} />);
    expect(button()).toBeNull();
    second.unmount();

    passkeys.supported = true;
    renderWithProviders(<Form onSubmit={() => {}} />);
    expect(button()).not.toBeNull();
});

test('a verified passkey submits the form for its purpose with the typed password emptied', async () => {
    const onSubmit = vi.fn();
    renderWithProviders(<Form onSubmit={onSubmit} />);

    fireEvent.click(button()!);
    await act(() => Promise.resolve());

    expect(passkeys.verify.mock.calls[0]?.[0].routes).toEqual({
        options: '/member/reauth/passkey/options?purpose=withdrawal',
        submit: '/member/reauth/passkey',
    });
    expect(onSubmit).toHaveBeenCalledTimes(1);
    expect(onSubmit.mock.calls[0]?.[0].current_password).toBe('');
});

test('a refused ceremony is reported and submits nothing', async () => {
    passkeys.verify.mockRejectedValue(new Error('Passkey not recognized.'));
    const onSubmit = vi.fn();
    renderWithProviders(<Form onSubmit={onSubmit} />);

    fireEvent.click(button()!);
    await act(() => Promise.resolve());

    expect(screen.getByRole('alert').textContent).toBe('Passkey not recognized.');
    expect(onSubmit).not.toHaveBeenCalled();
});
