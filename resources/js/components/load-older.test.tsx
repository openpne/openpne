import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, expect, test, vi } from 'vitest';
import { LoadOlder } from './load-older';
import { fakeT } from '@/lib/test-i18n';

vi.mock('@/lib/i18n', () => ({ useT: () => fakeT }));

interface Slot {
    fetch: () => void;
    loading: boolean;
    hasMore: boolean;
}

const scroll = vi.hoisted(() => ({ slot: { fetch: () => {}, loading: false, hasMore: true } as Slot }));

vi.mock('@inertiajs/react', () => ({
    InfiniteScroll: ({ children, next }: { children: ReactNode; next: (slot: Slot) => ReactNode }) => (
        <div>
            {children}
            {next(scroll.slot)}
        </div>
    ),
}));

afterEach(cleanup);

test('the button asks for the older page and stays out of the way while it loads', () => {
    const fetch = vi.fn();
    scroll.slot = { fetch, loading: false, hasMore: true };
    render(<LoadOlder data="posts">rows</LoadOlder>);

    fireEvent.click(screen.getByRole('button', { name: 'Load more' }));

    expect(fetch).toHaveBeenCalledOnce();

    cleanup();
    scroll.slot = { fetch, loading: true, hasMore: true };
    render(<LoadOlder data="posts">rows</LoadOlder>);

    const busy = screen.getByRole('button', { name: 'Loading…' });
    fireEvent.click(busy);

    expect(busy.getAttribute('aria-busy')).toBe('true');
    expect((busy as HTMLButtonElement).disabled).toBe(false);
    expect(fetch).toHaveBeenCalledOnce();
});

test('an exhausted stream offers no button', () => {
    scroll.slot = { fetch: () => {}, loading: false, hasMore: false };
    render(<LoadOlder data="posts">rows</LoadOlder>);

    expect(screen.queryByRole('button')).toBeNull();
    expect(screen.getByText('rows')).toBeTruthy();
});
