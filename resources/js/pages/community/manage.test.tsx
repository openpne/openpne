import { cleanup, fireEvent, screen, waitFor } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, expect, test, vi } from 'vitest';
import CommunityManage from './manage';
import { fakeT } from '@/lib/test-i18n';
import { renderWithProviders } from '@/lib/test-render';

vi.mock('@/lib/i18n', () => ({ useT: () => fakeT }));
vi.mock('@/components/confirm-dialog', () => ({ useConfirm: () => () => Promise.resolve(true) }));

const inertia = vi.hoisted(() => ({ page: {} as { url: string; props: Record<string, unknown> }, post: vi.fn() }));

vi.mock('@inertiajs/react', () => ({
    usePage: () => inertia.page,
    Link: ({ href, children, ...rest }: { href: string; children?: ReactNode }) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
    Head: () => null,
    router: { post: inertia.post },
}));

afterEach(() => {
    cleanup();
    inertia.post.mockReset();
});

const row = { imageUrl: null, avatarColor: null, isAi: false };

test.each([
    ['Drop this member', 'drop', 7],
    ['Appoint', 'appoint', 7],
    ['Demote', 'demote', 8],
])('%s sends the page the member was listed on', async (label, path, memberId) => {
    inertia.page = {
        url: '/groups/2/members/manage?page=2',
        props: {
            group: { id: 2, name: 'A group', description: '', memberCount: 22, imageUrl: null, category: null },
            members: {
                data: [
                    { ...row, id: 7, name: 'Aoi', role: 'member' },
                    { ...row, id: 8, name: 'Rin', role: 'sub_admin' },
                ],
                meta: { currentPage: 2, lastPage: 2, perPage: 20, total: 22 },
            },
            viewerRole: 'admin',
            pendingAdminId: null,
        },
    };
    renderWithProviders(<CommunityManage />);

    fireEvent.click(screen.getByRole('button', { name: label }));

    await waitFor(() => expect(inertia.post).toHaveBeenCalledWith(`/groups/2/members/${path}`, { member_id: memberId, page: 2 }, expect.anything()));
});

test('Transfer sends the page the member was listed on', async () => {
    inertia.page = {
        url: '/groups/2/members/manage?page=2',
        props: {
            group: { id: 2, name: 'A group', description: '', memberCount: 21, imageUrl: null, category: null },
            members: {
                data: [{ ...row, id: 7, name: 'Aoi', role: 'member' }],
                meta: { currentPage: 2, lastPage: 2, perPage: 20, total: 21 },
            },
            viewerRole: 'admin',
            pendingAdminId: null,
        },
    };
    renderWithProviders(<CommunityManage />);

    fireEvent.click(screen.getByRole('button', { name: 'Transfer' }));

    await waitFor(() => expect(inertia.post).toHaveBeenCalledWith('/groups/2/members/transfer', { member_id: 7, page: 2 }, expect.anything()));
});
