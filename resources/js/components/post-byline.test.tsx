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

    const name = container.querySelector('a[href="/member/3"]:not([aria-hidden])');
    const date = container.querySelector('time');

    expect(name?.textContent).toBe('Rin');
    expect(date?.getAttribute('datetime')).toBe(at);
    expect(name?.parentElement).not.toBe(date?.parentElement);
    expect(name?.parentElement?.contains(date ?? null)).toBe(false);
});

test('the avatar and the name lead where the page says, and only the name is offered to a keyboard', () => {
    const { container } = render(<PostByline author={author} href="/member/3/timeline" at={at} />);

    const links = [...container.querySelectorAll('a')];
    expect(links.map((a) => a.getAttribute('href'))).toEqual(['/member/3/timeline', '/member/3/timeline']);
    expect(links.map((a) => a.getAttribute('aria-hidden'))).toEqual(['true', null]);
    expect(links.map((a) => a.getAttribute('tabindex'))).toEqual(['-1', null]);
    expect(links.at(1)?.textContent).toBe('Rin');
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

test('the menu the page gives sits after the name and the date', () => {
    const { container } = render(<PostByline author={author} at={at} menu={<button type="button">more</button>} />);

    const button = container.querySelector('button');
    expect(button?.textContent).toBe('more');
    expect(button?.compareDocumentPosition(container.querySelector('time') as Node)).toBe(Node.DOCUMENT_POSITION_PRECEDING);
});
