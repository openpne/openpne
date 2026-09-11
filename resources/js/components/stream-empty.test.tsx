import { cleanup, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, expect, test, vi } from 'vitest';
import { StreamEmpty, StreamHead } from './stream-empty';
import { fakeT } from '@/lib/test-i18n';

vi.mock('@/lib/i18n', () => ({ useT: () => fakeT }));

vi.mock('@inertiajs/react', () => ({
    Link: ({ href, children, ...rest }: { href: string; children?: ReactNode }) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
}));

afterEach(cleanup);

test('an empty head says the stream is empty and offers no way back', () => {
    render(<StreamEmpty headUrl={null} empty="No posts to show." older="No older posts." />);

    expect(screen.getByText('No posts to show.')).toBeTruthy();
    expect(screen.queryByText('No older posts.')).toBeNull();
    expect(screen.queryByRole('link')).toBeNull();
});

test('a cursor page with no rows left says so and leads back to the head', () => {
    render(<StreamEmpty headUrl="/timeline" empty="No posts to show." older="No older posts." />);

    expect(screen.getByText('No older posts.')).toBeTruthy();
    expect(screen.queryByText('No posts to show.')).toBeNull();
    expect(screen.getByRole('link', { name: 'Jump to latest' }).getAttribute('href')).toBe('/timeline');
});

test('the head link stands on its own above a cursor page that still has rows, and not at the head', () => {
    const { container } = render(<StreamHead headUrl={null} />);
    expect(container.innerHTML).toBe('');

    render(<StreamHead headUrl="/notifications" />);
    expect(screen.getByRole('link', { name: 'Jump to latest' }).getAttribute('href')).toBe('/notifications');
});
