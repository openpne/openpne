import { cleanup, fireEvent, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, expect, test, vi } from 'vitest';
import MemberAvatar from './avatar';
import AiAccountShow from './config/ai/show';
import { fakeT } from '@/lib/test-i18n';
import { renderWithProviders } from '@/lib/test-render';

vi.mock('@/lib/i18n', () => ({ useT: () => fakeT }));
vi.mock('@/components/confirm-dialog', () => ({ useConfirm: () => () => Promise.resolve(false) }));

const inertia = vi.hoisted(() => ({
    page: {} as { url: string; props: Record<string, unknown> },
    posts: [] as Array<{ url: string; succeed: () => void }>,
}));

vi.mock('@inertiajs/react', async () => {
    const { useState } = await import('react');

    return {
        usePage: () => inertia.page,
        Link: ({ href, children, ...rest }: { href: string; children?: ReactNode }) => (
            <a href={href} {...rest}>
                {children}
            </a>
        ),
        Head: () => null,
        router: { post: () => {}, get: () => {} },
        useForm: <T extends Record<string, unknown>>(initial: T) => {
            const [data, setAll] = useState(initial);

            return {
                data,
                errors: {},
                processing: false,
                setData: (key: keyof T, value: T[keyof T]) => setAll((held) => ({ ...held, [key]: value })),
                setDefaults: () => {},
                reset: () => setAll(initial),
                delete: () => {},
                post: (url: string, options: { onSuccess?: () => void } = {}) => {
                    inertia.posts.push({ url, succeed: () => options.onSuccess?.() });
                },
            };
        },
    };
});

afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
    inertia.posts = [];
});

const shared = { locale: 'en', timezone: 'Asia/Tokyo', imageUpload: { accept: 'image/png', shrink: null }, auth: { user: { id: 1, name: 'Rin' } } };

function choose(label: string): HTMLFormElement {
    const field = screen.getByLabelText(label) as HTMLInputElement;
    const picked = new File(['x'], 'me.png', { type: 'image/png' });
    fireEvent.change(field, { target: { files: [picked] } });

    return field.closest('form') as HTMLFormElement;
}

// The reset is what is asserted, not the emptied field: the files a test hands an input are a
// property the testing library defines on it, which a reset does not reach.
test.each([
    [
        'a member',
        '/member/avatar',
        'Choose Image',
        () => {
            inertia.page = { url: '/member/avatar', props: { ...shared, avatar: null, badgeColor: { value: null, options: [] } } };

            return <MemberAvatar />;
        },
    ],
    [
        'an AI account',
        '/member/config/ai/7/avatar',
        'Profile image',
        () => {
            inertia.page = {
                url: '/member/config/ai/7',
                props: {
                    ...shared,
                    account: { id: 7, name: 'Helper', imageUrl: null, avatarColor: null, isAi: true },
                    selfIntroduction: null,
                    groups: null,
                    tokens: { tokens: [], requiresPassword: false, mcpEnabled: true, newToken: null },
                },
            };

            return <AiAccountShow />;
        },
    ],
] as const)('the picture form of %s is reset once the upload has landed, and not before', (_, url, label, page) => {
    const reset = vi.spyOn(HTMLFormElement.prototype, 'reset');
    renderWithProviders(page());
    const form = choose(label);

    fireEvent.submit(form);
    expect(inertia.posts.map((post) => post.url)).toEqual([url]);
    expect(reset).not.toHaveBeenCalled();

    inertia.posts[0]?.succeed();

    expect(reset.mock.contexts).toEqual([form]);
});
