import { cleanup, fireEvent, screen } from '@testing-library/react';
import { afterEach, expect, test, vi } from 'vitest';
import { RowSheet, rowSheetOpens } from './row-sheet';
import { fakeT } from '@/lib/test-i18n';
import { renderWithProviders } from '@/lib/test-render';

vi.mock('@/lib/i18n', () => ({ useT: () => fakeT }));

afterEach(() => {
    cleanup();
    delete (navigator as { clipboard?: unknown }).clipboard;
});

const chips = [{ emoji: '\u{1F44D}', count: 1, mine: false }];

function open(over: Partial<Parameters<typeof RowSheet>[0]> = {}) {
    const row = document.createElement('li');
    const spies = { onToggle: vi.fn(), onShowReactors: vi.fn(), onDelete: vi.fn(), onSelectText: vi.fn(), onClose: vi.fn() };
    renderWithProviders(
        <RowSheet body="words" author={{ id: 3, name: 'Rin', imageUrl: null, avatarColor: null, isAi: false }} createdAt="2026-09-19T10:00:00+09:00" chips={chips} vocabulary={['\u{1F44D}']} canReact onToggle={spies.onToggle} onShowReactors={spies.onShowReactors} onDelete={spies.onDelete} returnFocusTo={row} onSelectText={spies.onSelectText} onClose={spies.onClose} {...over} />,
    );

    return { row, spies };
}

test('the reactor list and the delete are asked with the pressed row, so what they open can give focus back to it', () => {
    const { row, spies } = open();

    fireEvent.click(screen.getByRole('button', { name: 'See who reacted' }));
    expect(spies.onShowReactors).toHaveBeenCalledWith(row);
    expect(spies.onClose).toHaveBeenCalledTimes(1);

    fireEvent.click(screen.getByRole('button', { name: 'Delete' }));
    expect(spies.onDelete).toHaveBeenCalledWith(row);
});

test('the reactor list is offered only to a reader given a way to it, chips or no chips', () => {
    open({ onShowReactors: undefined });

    expect(screen.queryByRole('button', { name: 'See who reacted' })).toBeNull();
});

test('a press has something to open only when the sheet would hold an item', () => {
    const nothing = { body: '   ', chips: [], canReact: false };
    expect(rowSheetOpens(nothing)).toBe(false);
    expect(rowSheetOpens({ ...nothing, chips, onShowReactors: () => {} })).toBe(true);
    expect(rowSheetOpens({ ...nothing, chips })).toBe(false);
    expect(rowSheetOpens({ ...nothing, body: 'words' })).toBe(true);
    expect(rowSheetOpens({ ...nothing, onDelete: () => {} })).toBe(true);
});

test('select text hands the row\'s words on with who wrote them and when', () => {
    const { spies } = open();

    fireEvent.click(screen.getByRole('button', { name: 'Select text' }));
    expect(spies.onSelectText).toHaveBeenCalledWith({ body: 'words', author: { id: 3, name: 'Rin', imageUrl: null, avatarColor: null, isAi: false }, createdAt: '2026-09-19T10:00:00+09:00' });
});
