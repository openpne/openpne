import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { type ReactNode, useEffect } from 'react';
import { afterEach, expect, test, vi } from 'vitest';
import { LoadOlder } from './load-older';
import { fakeT } from '@/lib/test-i18n';

vi.mock('@/lib/i18n', () => ({ useT: () => fakeT }));

interface Slot {
    fetch: () => void;
    loading: boolean;
    hasMore: boolean;
}

const scroll = vi.hoisted(() => ({
    slot: { fetch: () => {}, loading: false, hasMore: true } as Slot,
    props: {} as Record<string, unknown>,
    mounts: 0,
}));

vi.mock('@inertiajs/react', () => ({
    InfiniteScroll: ({ children, next, ...rest }: { children: ReactNode; next: (slot: Slot) => ReactNode }) => {
        scroll.props = rest;
        useEffect(() => {
            scroll.mounts += 1;
        }, []);
        return (
            <div>
                {children}
                {next(scroll.slot)}
            </div>
        );
    },
}));

afterEach(cleanup);

test('the list is manual, older-only and leaves the URL alone', () => {
    scroll.slot = { fetch: () => {}, loading: false, hasMore: true };
    render(
        <LoadOlder data="posts" generation="g1">
            rows
        </LoadOlder>,
    );

    expect(scroll.props).toMatchObject({ data: 'posts', manual: true, onlyNext: true, preserveUrl: true });
});

test('the button asks for the older page and stays focusable while it loads', () => {
    const fetch = vi.fn();
    scroll.slot = { fetch, loading: false, hasMore: true };
    render(
        <LoadOlder data="posts" generation="g1">
            rows
        </LoadOlder>,
    );

    fireEvent.click(screen.getByRole('button', { name: 'Load more' }));

    expect(fetch).toHaveBeenCalledOnce();

    cleanup();
    scroll.slot = { fetch, loading: true, hasMore: true };
    render(
        <LoadOlder data="posts" generation="g1">
            rows
        </LoadOlder>,
    );
    const busy = screen.getByRole('button', { name: 'Loading…' });
    fireEvent.click(busy);

    expect(busy.getAttribute('aria-busy')).toBe('true');
    expect((busy as HTMLButtonElement).disabled).toBe(false);
    expect(fetch).toHaveBeenCalledOnce();
});

test('a list that fits its first page shows neither a button nor an end line', () => {
    scroll.slot = { fetch: () => {}, loading: false, hasMore: false };
    render(
        <LoadOlder data="posts" generation="g1">
            rows
        </LoadOlder>,
    );

    expect(screen.queryByRole('button')).toBeNull();
    expect(screen.queryByRole('status')).toBeNull();
    expect(screen.getByText('rows')).toBeTruthy();
});

test('an exhausted stream says so where the button was and takes the focus there', () => {
    scroll.slot = { fetch: () => {}, loading: false, hasMore: true };
    const view = render(
        <LoadOlder data="posts" generation="g1">
            rows
        </LoadOlder>,
    );
    screen.getByRole('button', { name: 'Load more' }).focus();

    scroll.slot = { fetch: () => {}, loading: false, hasMore: false };
    view.rerender(
        <LoadOlder data="posts" generation="g1">
            rows
        </LoadOlder>,
    );

    expect(screen.queryByRole('button')).toBeNull();
    expect(screen.getByRole('status').textContent).toBe('No older posts.');
    expect(document.activeElement).toBe(screen.getByRole('status'));
});

test('a stream can name what ran out in its own words', () => {
    scroll.slot = { fetch: () => {}, loading: false, hasMore: true };
    const view = render(
        <LoadOlder data="diaries" generation="g1" end="No older diaries.">
            rows
        </LoadOlder>,
    );
    scroll.slot = { fetch: () => {}, loading: false, hasMore: false };
    view.rerender(
        <LoadOlder data="diaries" generation="g1" end="No older diaries.">
            rows
        </LoadOlder>,
    );

    expect(screen.getByRole('status').textContent).toBe('No older diaries.');
});

test('a new generation remounts the list, an unchanged one does not', () => {
    scroll.slot = { fetch: () => {}, loading: false, hasMore: true };
    scroll.mounts = 0;
    const view = render(
        <LoadOlder data="posts" generation="g1">
            rows
        </LoadOlder>,
    );
    view.rerender(
        <LoadOlder data="posts" generation="g1">
            rows
        </LoadOlder>,
    );
    expect(scroll.mounts).toBe(1);

    view.rerender(
        <LoadOlder data="posts" generation="g2">
            rows
        </LoadOlder>,
    );
    expect(scroll.mounts).toBe(2);
});
