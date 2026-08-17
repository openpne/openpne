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
    expect(seatRows(9)).toEqual([4, 5]);
});

/**
 * The counts below nine are what a small group or a new member actually has, and the four-seat top row
 * is held even then: rows that split evenly (three and three for six) would line the columns up and
 * lose the stagger the formation is for.
 */
test('a short count fills the seat map from the top row down', () => {
    expect(seatRows(0)).toEqual([]);
    expect(seatRows(1)).toEqual([1]);
    expect(seatRows(4)).toEqual([4]);
    expect(seatRows(5)).toEqual([4, 1]);
    expect(seatRows(6)).toEqual([4, 2]);
    expect(seatRows(8)).toEqual([4, 4]);
});

/** Nothing caps the component: a caller that asks for more gets more rows, in the same rhythm. */
test('a count past the second row keeps alternating', () => {
    expect(seatRows(10)).toEqual([4, 5, 1]);
    expect(seatRows(18)).toEqual([4, 5, 4, 5]);
});

test('the rows are drawn with the seats of their rank, not of their contents', () => {
    const { container } = render(<PeopleGrid people={people(6)} />);
    const rows = container.querySelectorAll('ul');

    expect(rows).toHaveLength(2);
    // The second row holds two faces but is still a five-seat row, which is what leaves it ragged.
    expect(rows[0]?.className).toContain('grid-cols-4');
    expect(rows[1]?.className).toContain('grid-cols-5');
});

/** The narrower spread of the four-seat row is what puts one row's faces between the other's. */
test('the four-seat row is spread narrower than the five', () => {
    const { container } = render(<PeopleGrid people={people(9)} />);

    expect(container.querySelectorAll('ul')[0]?.className).toContain('mx-[4%]');
    expect(container.querySelectorAll('ul')[1]?.className).not.toContain('mx-[');
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
