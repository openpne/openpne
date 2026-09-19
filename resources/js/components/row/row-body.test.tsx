import { cleanup, fireEvent, screen } from '@testing-library/react';
import { afterEach, expect, test, vi } from 'vitest';
import { RowBody } from './row-body';
import { fakeT } from '@/lib/test-i18n';
import { renderWithProviders } from '@/lib/test-render';

vi.mock('@/lib/i18n', () => ({ useT: () => fakeT }));

const coarse = vi.hoisted(() => ({ value: false }));
vi.mock('@/lib/use-coarse-pointer', () => ({ useCoarsePointer: () => coarse.value }));

afterEach(() => {
    cleanup();
    coarse.value = false;
});

const vocabulary = ['\u{1F44D}', '\u{2764}\u{FE0F}'];

test('a reader who may react gets the add button beside the body and no chip row until there is one', () => {
    const onToggle = vi.fn();
    const { container, rerender } = renderWithProviders(
        <RowBody reactions={{ chips: [], vocabulary, onToggle }}>
            <p>words</p>
        </RowBody>,
    );

    expect(screen.getByRole('button', { name: 'Add a reaction' })).toBeTruthy();
    expect(container.querySelector('[data-reactions]')).toBeNull();

    rerender(
        <RowBody reactions={{ chips: [{ emoji: '\u{1F44D}', count: 1, mine: true }], vocabulary, onToggle }}>
            <p>words</p>
        </RowBody>,
    );
    fireEvent.click(screen.getByRole('button', { pressed: true }));
    expect(onToggle).toHaveBeenCalledWith('\u{1F44D}', true);
});

test('a reader who may not react gets the body and the counts, with nothing to press', () => {
    renderWithProviders(
        <RowBody reactions={{ chips: [{ emoji: '\u{1F44D}', count: 3, mine: false }], vocabulary }}>
            <p>words</p>
        </RowBody>,
    );

    expect(screen.getByText('3')).toBeTruthy();
    expect(screen.queryAllByRole('button')).toHaveLength(0);
});

test('the trailing slot stands in the column with the add button, for a row that has no header to hold it', () => {
    renderWithProviders(
        <RowBody reactions={{ chips: [], vocabulary, onToggle: vi.fn() }} trailing={<button type="button">more</button>}>
            <p>words</p>
        </RowBody>,
    );

    const column = screen.getByRole('button', { name: 'more' }).parentElement;
    expect(column?.contains(screen.getByRole('button', { name: 'Add a reaction' }))).toBe(true);
});

test('the picker opens as a popover for a cursor and as a sheet for a finger, and a pick closes either', () => {
    const onToggle = vi.fn();
    renderWithProviders(
        <RowBody reactions={{ chips: [], vocabulary, onToggle }}>
            <p>words</p>
        </RowBody>,
    );
    fireEvent.click(screen.getByRole('button', { name: 'Add a reaction' }));
    expect(screen.getByRole('dialog', { name: 'Reactions' })).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: vocabulary[1] }));
    expect(onToggle).toHaveBeenCalledWith(vocabulary[1], false);
    expect(screen.queryByRole('dialog', { name: 'Reactions' })).toBeNull();

    cleanup();
    coarse.value = true;
    renderWithProviders(
        <RowBody reactions={{ chips: [], vocabulary, onToggle }}>
            <p>words</p>
        </RowBody>,
    );
    fireEvent.click(screen.getByRole('button', { name: 'Add a reaction' }));
    expect(screen.getByRole('dialog', { name: 'Reactions' }).className).toContain('bottom-0');
    fireEvent.click(screen.getByRole('button', { name: vocabulary[0] }));
    expect(onToggle).toHaveBeenLastCalledWith(vocabulary[0], false);
    expect(screen.queryByRole('dialog', { name: 'Reactions' })).toBeNull();
});
