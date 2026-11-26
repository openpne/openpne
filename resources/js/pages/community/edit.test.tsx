import { cleanup, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, expect, test, vi } from 'vitest';
import CommunityEdit from './edit';
import { fakeT } from '@/lib/test-i18n';
import { renderWithProviders } from '@/lib/test-render';

vi.mock('@/lib/i18n', () => ({ useT: () => fakeT }));
vi.mock('@/components/confirm-dialog', () => ({ useConfirm: () => () => Promise.resolve(true) }));

const inertia = vi.hoisted(() => ({
    page: {} as { url: string; props: Record<string, unknown> },
    formData: {} as Record<string, unknown>,
}));

vi.mock('@inertiajs/react', () => ({
    usePage: () => inertia.page,
    useForm: <T extends Record<string, unknown>>(initial: T) => {
        inertia.formData = initial;
        return { data: initial, setData: vi.fn(), errors: {}, processing: false, post: vi.fn() };
    },
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

function renderEdit(group: (Omit<typeof existing, 'categoryId'> & { categoryId: number | null }) | null) {
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

// The field names are GroupRequest::rules(): a key the request does not validate is dropped silently.
test('the category is submitted under the key the server validates', () => {
    renderEdit({ ...existing, categoryId: 7 });

    expect(inertia.formData.group_category_id).toBe('7');
    expect(Object.keys(inertia.formData).sort()).toEqual(
        [
            'description',
            'group_category_id',
            'image',
            'is_join_notification_enabled',
            'name',
            'register_policy',
            'remove_image',
            'topic_post_authority',
            'topic_read_access',
        ].sort(),
    );
});
