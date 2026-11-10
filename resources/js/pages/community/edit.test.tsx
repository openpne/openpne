import { cleanup, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, expect, test, vi } from 'vitest';
import CommunityEdit from './edit';
import { fakeT } from '@/lib/test-i18n';
import { renderWithProviders } from '@/lib/test-render';

vi.mock('@/lib/i18n', () => ({ useT: () => fakeT }));
vi.mock('@/components/confirm-dialog', () => ({ useConfirm: () => () => Promise.resolve(true) }));

const inertia = vi.hoisted(() => ({ page: {} as { url: string; props: Record<string, unknown> } }));

vi.mock('@inertiajs/react', () => ({
    usePage: () => inertia.page,
    useForm: <T extends object>(initial: T) => ({ data: initial, setData: vi.fn(), errors: {}, processing: false, post: vi.fn() }),
    Link: ({ href, children, ...rest }: { href: string; children?: ReactNode }) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
    Head: () => null,
    router: { post: vi.fn() },
}));

afterEach(cleanup);

const existing = {
    id: 3,
    name: 'Climbers',
    description: '',
    registerPolicy: 'open',
    categoryId: null,
    isJoinNotificationEnabled: true,
    topicReadAccess: 'everyone',
    topicPostAuthority: 'members',
    imageUrl: null,
};

function renderEdit(group: typeof existing | null) {
    inertia.page = {
        url: '/groups/edit',
        props: {
            group,
            categories: [],
            policies: [{ slug: 'open', label: 'Everyone can join' }],
            topicReadChoices: [{ slug: 'everyone', label: 'Everyone' }],
            topicPostChoices: [{ slug: 'members', label: 'Members' }],
            canDelete: false,
            auth: { user: { id: 1 } },
            locale: 'en',
            timezone: 'Asia/Tokyo',
            imageUpload: { accept: 'image/png', shrink: null },
        },
    };

    return renderWithProviders(<CommunityEdit />);
}

test('a new group is submitted with Create', () => {
    renderEdit(null);

    expect(screen.getByRole('button', { name: 'Create' })).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'Save' })).toBeNull();
});

test('an existing group is submitted with Save', () => {
    renderEdit(existing);

    expect(screen.getByRole('button', { name: 'Save' })).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'Create' })).toBeNull();
});
