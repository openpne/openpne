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

function description(): string | null {
    const id = screen.getByRole('dialog').getAttribute('aria-describedby');

    return id === null ? null : (document.getElementById(id)?.textContent ?? null);
}

test('the sheet names its row by who wrote it and how it begins, and is described by that line', () => {
    open({ body: '  first line\nsecond line  ' });

    expect(description()).toBe('Rin first line\nsecond line');
});

test('a withdrawn author is named as withdrawn, and a row with no author is named by its words alone', () => {
    open({ author: null });
    expect(description()).toBe('%Withdrawn_member% words');
    cleanup();

    open({ author: undefined });
    expect(description()).toBe('words');
});

test('a row with neither author nor words leaves the sheet undescribed', () => {
    open({ author: undefined, body: ' ', onDelete: () => {} });

    expect(screen.getByRole('dialog').getAttribute('aria-describedby')).toBeNull();
});

test('the pressed row is marked only while its sheet is open, over a page dimmed without blur', () => {
    const { row } = open();
    const scrim = screen.getByRole('dialog').previousElementSibling;

    expect(row.hasAttribute('data-sheet-target')).toBe(true);
    expect(scrim?.className).toContain('bg-black/25');
    expect(scrim?.className).not.toContain('backdrop-blur');

    cleanup();
    expect(row.hasAttribute('data-sheet-target')).toBe(false);
});
