import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, expect, test, vi } from 'vitest';
import { NineTable } from './nine-table';
import { fakeT } from '@/lib/test-i18n';
import type { NineTableItem } from '@/types';

// useT reads the Inertia page for its term map, which a component test has no page to give it.
vi.mock('@/lib/i18n', () => ({ useT: () => fakeT }));

afterEach(cleanup);

const item = (over: Partial<NineTableItem> = {}): NineTableItem => ({
    id: 1,
    name: 'Shirabe',
    imageUrl: null,
    avatarColor: null,
    isAi: false,
    href: '/member/1',
    ...over,
});

/**
 * A marker that lands outside the link's accessible name is invisible to axe either way: the link is
 * named, just not as an AI account.
 */
test('an AI tile is named as one', () => {
    render(<NineTable items={[item({ isAi: true })]} shape="round" />);

    expect(screen.getByRole('link', { name: 'Shirabe (AI)' })).toBeTruthy();
});

test('an AI tile carries the visible corner mark too', () => {
    const { container } = render(<NineTable items={[item({ isAi: true })]} shape="round" />);

    // aria-hidden, so it is the rendered text rather than the accessible name that proves it is drawn.
    expect(container.textContent).toContain('AI');
});

test("a person's tile is named by their name alone, and draws no mark", () => {
    const { container } = render(<NineTable items={[item()]} shape="round" />);

    expect(screen.getByRole('link', { name: 'Shirabe' })).toBeTruthy();
    expect(container.textContent).not.toContain('AI');
});

test('a group tile is unmarked — a group is never an AI account', () => {
    const { container } = render(<NineTable items={[item({ name: 'Book club', href: '/groups/1' })]} shape="square" />);

    expect(screen.getByRole('link', { name: 'Book club' })).toBeTruthy();
    expect(container.textContent).not.toContain('AI');
});

test('the tile still links where the item points', () => {
    render(<NineTable items={[item({ isAi: true })]} shape="round" />);

    expect(screen.getByRole('link').getAttribute('href')).toBe('/member/1');
});
