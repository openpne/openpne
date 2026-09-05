import { cleanup, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, expect, test, vi } from 'vitest';
import HomeIssues from './issues';
import { fakeT } from '@/lib/test-i18n';
import { renderWithProviders } from '@/lib/test-render';
import type { IssueRef } from './types';

vi.mock('@/lib/i18n', () => ({ useT: () => fakeT }));

const inertia = vi.hoisted(() => ({ page: {} as { component: string; url: string; props: Record<string, unknown> } }));

vi.mock('@inertiajs/react', () => ({
    usePage: () => inertia.page,
    Link: ({ href, children, ...rest }: { href: string; children?: ReactNode }) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
    Head: () => null,
}));

afterEach(cleanup);

const ref = (number: number, date: string): IssueRef => ({ date, number, href: `/home/${date.replaceAll('-', '/')}` });

function arrive(data: IssueRef[], meta: { currentPage: number; lastPage: number }) {
    inertia.page = {
        component: 'home/issues',
        url: '/home/issues',
        props: {
            locale: 'en',
            timezone: 'Asia/Tokyo',
            issues: { data, meta: { perPage: 30, total: 40, ...meta } },
        },
    };

    return renderWithProviders(<HomeIssues />);
}

test('each row is the day it covers, and links to it', () => {
    arrive([ref(12, '2026-08-27'), ref(11, '2026-08-26')], { currentPage: 1, lastPage: 2 });

    const latest = screen.getByRole('link', { name: 'Thu, August 27, 2026' });
    expect(latest.getAttribute('href')).toBe('/home/2026/08/27');
    expect(screen.getByRole('link', { name: 'Wed, August 26, 2026' }).getAttribute('href')).toBe('/home/2026/08/26');
    expect(screen.queryByText(/^No\. /)).toBeNull();
});

test('the run pages when there is more of it than one page', () => {
    arrive([ref(12, '2026-08-27')], { currentPage: 1, lastPage: 2 });

    expect(screen.getByRole('navigation', { name: 'Pagination Navigation' })).toBeTruthy();
    expect(screen.getByText('Page 1 of 2')).toBeTruthy();

    cleanup();

    const { container } = arrive([ref(12, '2026-08-27')], { currentPage: 1, lastPage: 1 });
    expect(screen.queryByText('Page 1 of 1')).toBeNull();
    expect(container.querySelector('nav')).toBeNull();
});

test('a run with nothing in it says so instead of drawing an empty list', () => {
    arrive([], { currentPage: 1, lastPage: 1 });

    expect(screen.getByText('Nothing yet.')).toBeTruthy();
    expect(screen.queryByRole('list')).toBeNull();
});
