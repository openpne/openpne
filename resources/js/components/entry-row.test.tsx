import { cleanup, render } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, expect, test, vi } from 'vitest';
import { EntryRow } from './entry-row';
import { fakeT } from '@/lib/test-i18n';

// useT reads the Inertia page for its term map, which a component test has no page to give it.
vi.mock('@/lib/i18n', () => ({ useT: () => fakeT }));

vi.mock('@inertiajs/react', () => ({
    Link: ({ href, children, ...rest }: { href: string; children?: ReactNode }) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
}));

afterEach(cleanup);

const author = { id: 3, name: 'Rin', imageUrl: null, avatarColor: null, isAi: false };

/** One row of a list that mixes the two: this one either carries its photos or only says it has them. */
function row(photos: 'strip' | 'marker') {
    const { container } = render(
        <ul>
            <EntryRow
                href="/diary/1"
                author={author}
                content="A title"
                date={<span>Aug 27</span>}
                excerpt="What it says."
                hasImages
                thumbnails={photos === 'strip' ? ['/f/1/thumb', '/f/2/thumb'] : undefined}
            />
        </ul>,
    );

    const li = container.querySelector('li');
    const column = li?.querySelector('.flex-1');
    if (!li || !column) {
        throw new Error('the row did not render its text column');
    }

    return { li, column };
}

/** Everything down to the content line — what decides how far the title sits from the row's top. */
function downToTheTitle(column: Element): string[] {
    const children = Array.from(column.children);
    const title = children.findIndex((child) => child.querySelector('a[href]') !== null);

    expect(title).toBeGreaterThanOrEqual(0);

    return children.slice(0, title + 1).map((child) => `${child.tagName}.${child.className}`);
}

/**
 * A list mixing rows that carry a photo strip with rows that only mark that they have photos must
 * read as one column of titles. Two things hold that: the row aligns to its own top, so a taller row
 * cannot centre its text against the short one beside it, and the byline keeps a line's height
 * whatever it happens to carry.
 */
test('a row with photos and one without are the same shape above the title', () => {
    const strip = row('strip');
    const marker = row('marker');

    expect(downToTheTitle(strip.column)).toEqual(downToTheTitle(marker.column));

    // The strip is the one thing that differs, and it hangs below the title rather than above it.
    expect(strip.column.querySelectorAll('img')).toHaveLength(2);
    expect(marker.column.querySelectorAll('img')).toHaveLength(0);
});

test('the row aligns to its top rather than centring on whatever is tallest', () => {
    const { li } = row('strip');

    expect(li.className).toContain('items-start');
    expect(li.className).not.toContain('items-center');
});

test('the byline holds a line whether or not it carries a marker', () => {
    const { column } = row('marker');

    expect(column.children[0]?.className).toContain('min-h-5');
});
