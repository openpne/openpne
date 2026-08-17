import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, expect, test, vi } from 'vitest';
import { fakeT } from '@/lib/test-i18n';
import type { NineTableItem } from '@/types';
import { PeopleGrid, seatRows } from './people-grid';

// useT reads the Inertia page for its term map, which a component test has no page to give it.
vi.mock('@/lib/i18n', () => ({ useT: () => fakeT }));

afterEach(cleanup);

const person = (over: Partial<NineTableItem> = {}): NineTableItem => ({
    id: 1,
    name: 'Shirabe',
    imageUrl: null,
    avatarColor: null,
    isAi: false,
    href: '/member/1',
    ...over,
});

const people = (count: number): NineTableItem[] =>
    Array.from({ length: count }, (_, index) => person({ id: index + 1, name: `Member ${index + 1}`, href: `/member/${index + 1}` }));

test('a full grid is the four-then-five formation the design asks for', () => {
    expect(seatRows(9)).toEqual([
        { seats: 4, filled: 4 },
        { seats: 5, filled: 5 },
    ]);
});

/**
 * The counts below nine are what a small group or a new member actually has. Six and up keep the
 * four-seat top row — rows that split evenly (three and three) would line the columns up and lose the
 * stagger — but five and under are one row, since a second row of one has nothing to stagger against.
 */
test('a short count fills the seat map from the top row down', () => {
    expect(seatRows(0)).toEqual([]);
    expect(seatRows(1)).toEqual([{ seats: 4, filled: 1 }]);
    // Four spread over four seats, not over five with one left empty.
    expect(seatRows(4)).toEqual([{ seats: 4, filled: 4 }]);
    expect(seatRows(5)).toEqual([{ seats: 5, filled: 5 }]);
    expect(seatRows(6)).toEqual([
        { seats: 4, filled: 4 },
        { seats: 5, filled: 2 },
    ]);
    expect(seatRows(8)).toEqual([
        { seats: 4, filled: 4 },
        { seats: 5, filled: 4 },
    ]);
});

/** Nothing caps the component: a caller that asks for more gets more rows, in the same rhythm. */
test('a count past the second row keeps alternating', () => {
    expect(seatRows(10)).toEqual([
        { seats: 4, filled: 4 },
        { seats: 5, filled: 5 },
        { seats: 4, filled: 1 },
    ]);
});

/**
 * The seats of a row are spans over one forty-column grid, so a row of four sits at a wider pitch than
 * a row of five and starts two columns in — the two together are the stagger — while the faces stay one
 * list. Four nines from column three end at column 38, which is what wraps the fifth seat to a row of
 * its own.
 */
test('a row of four seats faces wider than a row of five, and held off the edge', () => {
    const { container } = render(<PeopleGrid people={people(6)} />);
    const seats = [...container.querySelectorAll('li')].map((li) => li.className);

    expect(container.querySelectorAll('ul')).toHaveLength(1);
    expect(seats).toEqual(['col-span-9 col-start-3', 'col-span-9', 'col-span-9', 'col-span-9', 'col-span-8', 'col-span-8']);
});

test('a count that fits one row is spread over the seats it fills', () => {
    const { container } = render(<PeopleGrid people={people(3)} />);

    expect([...container.querySelectorAll('li')].map((li) => li.className)).toEqual(['col-span-9 col-start-3', 'col-span-9', 'col-span-9']);
});

test('every face is seated exactly once, in order', () => {
    render(<PeopleGrid people={people(9)} />);
    const links = screen.getAllByRole('link');

    expect(links.map((link) => link.getAttribute('href'))).toEqual(people(9).map((seated) => seated.href));
});

/**
 * The faces carry no visible name — the row is decoration, and the roster it links to is where names
 * are read — so the accessible name is all a screen reader has to go on.
 */
test('a face is named to the a11y tree and not on the page', () => {
    const { container } = render(<PeopleGrid people={[person()]} />);

    expect(screen.getByRole('link', { name: 'Shirabe' })).toBeTruthy();
    // The initial badge stands in for a missing photo; the name itself is nowhere on the page.
    expect(container.textContent).not.toContain('Shirabe');
});

test('an AI account is named as one, and wears the corner mark', () => {
    const { container } = render(<PeopleGrid people={[person({ isAi: true })]} />);

    expect(screen.getByRole('link', { name: 'Shirabe (AI)' })).toBeTruthy();
    // aria-hidden, so the rendered text is what proves it is drawn.
    expect(container.textContent).toContain('AI');
});
