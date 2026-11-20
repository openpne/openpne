import { cleanup, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, expect, test, vi } from 'vitest';
import GroupEventMembers from './members';
import { fakeT } from '@/lib/test-i18n';
import { renderWithProviders } from '@/lib/test-render';

vi.mock('@/lib/i18n', () => ({ useT: () => fakeT }));

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({
        component: 'group/event/members',
        url: '/events/12/members',
        props: {
            event: { id: 12, name: 'Ridge walk' },
            participants: { data: [], meta: { currentPage: 1, lastPage: 1, perPage: 50, total: 0 } },
        },
    }),
    Link: ({ href, children, ...rest }: { href: string; children?: ReactNode }) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
    Head: () => null,
}));

afterEach(cleanup);

test("the participants page leads to its event, the one level above it the group's tiles do not offer", () => {
    renderWithProviders(<GroupEventMembers />);

    expect(screen.getByRole('link', { name: 'Ridge walk' }).getAttribute('href')).toBe('/events/12');
});
