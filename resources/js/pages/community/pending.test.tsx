import { cleanup, fireEvent, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, expect, test, vi } from 'vitest';
import CommunityPending from './pending';
import { fakeT } from '@/lib/test-i18n';
import { renderWithProviders } from '@/lib/test-render';

vi.mock('@/lib/i18n', () => ({ useT: () => fakeT }));

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

test.each([
    ['Approve', 'approve'],
    ['Decline', 'decline'],
])('%s sends the page the applicant was listed on', (label, path) => {
    inertia.page = {
        url: '/groups/2/members/pending?page=2',
        props: {
            group: { id: 2, name: 'A group', description: '', memberCount: 1, imageUrl: null, category: null },
            applicants: {
                data: [{ id: 7, name: 'Aoi', imageUrl: null, avatarColor: null, isAi: false }],
                meta: { currentPage: 2, lastPage: 2, perPage: 20, total: 21 },
            },
        },
    };
    renderWithProviders(<CommunityPending />);

    fireEvent.click(screen.getByRole('button', { name: label }));

    expect(inertia.post).toHaveBeenCalledWith(`/groups/2/members/${path}`, { member_id: 7, page: 2 }, expect.anything());
});
