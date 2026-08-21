import { cleanup, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, expect, test, vi } from 'vitest';
import { TimelineReplyRow } from './reply-row';
import { fakeT } from '@/lib/test-i18n';
import type { TimelinePostEntry } from './types';

vi.mock('@/lib/i18n', () => ({ useT: () => fakeT }));

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { locale: 'en', timezone: 'Asia/Tokyo' } }),
    Link: ({ href, children, ...rest }: { href: string; children?: ReactNode }) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
}));

afterEach(cleanup);

const reply: TimelinePostEntry = {
    id: 7,
    body: 'The good one is the second link',
    author: { id: 3, name: 'Rin', imageUrl: null, avatarColor: null, isAi: false },
    visibility: 'open',
    hasImages: false,
    replyCount: 0,
    images: [],
    mentions: [],
    tags: [],
    linkCard: null,
    createdAt: '2026-08-21T10:00:00+09:00',
};

const card = {
    url: 'https://www.example.com/article',
    title: 'A title from the page',
    description: 'What the page says it is about.',
    siteName: 'Example',
    domain: 'example.com',
    layout: 'compact' as const,
    imageUrl: '/linkCard/timeline/7/img/png/w120_h120_sq/a.png',
    imageWidth: 120,
    imageHeight: 120,
    fitSources: [],
};

test('a reply draws the card its body earned', () => {
    // The server side of this shipped once with nothing drawn: a payload assertion sees `linkCard`
    // on the row and says nothing about whether anyone renders it.
    render(<TimelineReplyRow reply={{ ...reply, linkCard: card }} viewerId={1} onDelete={vi.fn()} />);

    expect(screen.getByText('A title from the page')).toBeTruthy();
    expect(screen.getByText('example.com')).toBeTruthy();
});

test('a reply with no card draws only its words', () => {
    const { container } = render(<TimelineReplyRow reply={reply} viewerId={1} onDelete={vi.fn()} />);

    expect(container.querySelector('a[rel*="nofollow"]')).toBeNull();
    expect(screen.getByText('The good one is the second link')).toBeTruthy();
});

test('only the reply author is offered the delete control', () => {
    render(<TimelineReplyRow reply={reply} viewerId={3} onDelete={vi.fn()} />);
    expect(screen.getByRole('button', { name: 'Delete' })).toBeTruthy();

    cleanup();
    render(<TimelineReplyRow reply={reply} viewerId={1} onDelete={vi.fn()} />);
    expect(screen.queryByRole('button', { name: 'Delete' })).toBeNull();
});
