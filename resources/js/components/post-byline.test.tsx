import { cleanup, render } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, expect, test, vi } from 'vitest';
import { PostByline } from './post-byline';
import { fakeT } from '@/lib/test-i18n';

vi.mock('@/lib/i18n', () => ({ useT: () => fakeT }));

vi.mock('@inertiajs/react', () => ({
    Link: ({ href, children, ...rest }: { href: string; children?: ReactNode }) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
}));

vi.mock('@/components/timestamp', () => ({
    Timestamp: ({ at }: { at: string }) => <time dateTime={at}>{at}</time>,
}));

afterEach(cleanup);

const author = { id: 3, name: 'Rin', imageUrl: null, avatarColor: null, isAi: false };
const at = '2026-11-12T13:00:00Z';

test('the name and the date are on lines of their own', () => {
    const { container } = render(<PostByline author={author} at={at} />);

    const name = container.querySelector('a[href="/member/3"]');
    const date = container.querySelector('time');

    expect(name?.textContent).toBe('Rin');
    expect(date?.getAttribute('datetime')).toBe(at);
    expect(name?.parentElement).not.toBe(date?.parentElement);
    expect(name?.parentElement?.contains(date ?? null)).toBe(false);
});

test('the name leads where the page says', () => {
    const { container } = render(<PostByline author={author} href="/member/3/timeline" at={at} />);

    expect(container.querySelector('a')?.getAttribute('href')).toBe('/member/3/timeline');
});

test('a withdrawn author has a placeholder and no link', () => {
    const { container } = render(<PostByline author={null} at={at} />);

    expect(container.querySelector('a')).toBeNull();
    expect(container.textContent).toContain('%Withdrawn_member%');
});

test('an edit is marked beside the date', () => {
    const plain = render(<PostByline author={author} at={at} />);
    expect(plain.container.textContent).not.toContain('Edited');
    cleanup();

    const edited = render(<PostByline author={author} at={at} edited />);
    expect(edited.container.querySelector('time')?.parentElement?.textContent).toContain('(Edited)');
});

test('the menu is shown only when the page gives one', () => {
    const without = render(<PostByline author={author} at={at} />);
    expect(without.container.querySelector('button')).toBeNull();
    cleanup();

    const withMenu = render(<PostByline author={author} at={at} menu={<button type="button">more</button>} />);
    expect(withMenu.container.querySelector('button')?.textContent).toBe('more');
});
