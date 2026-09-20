import { act, cleanup, fireEvent, screen } from '@testing-library/react';
import { afterEach, expect, test, vi } from 'vitest';
import { SelectTextSheet } from './select-text-sheet';
import { fakeT } from '@/lib/test-i18n';
import { renderWithProviders } from '@/lib/test-render';

vi.mock('@/lib/i18n', () => ({ useT: () => fakeT }));
vi.mock('@inertiajs/react', () => ({ usePage: () => ({ props: { locale: 'en', timezone: 'Asia/Tokyo' } }) }));

afterEach(() => {
    cleanup();
    window.getSelection()?.removeAllRanges();
    delete (navigator as { clipboard?: unknown }).clipboard;
});

const text = { body: 'line one\nline two', author: { id: 3, name: 'Rin', imageUrl: null, avatarColor: null, isAi: false }, createdAt: '2026-09-19T10:00:00+09:00' };

test('the row is drawn again under a heading with its body already selected, and closing gives focus back to the row it came from', async () => {
    const row = document.createElement('li');
    row.tabIndex = -1;
    document.body.append(row);
    const onClose = vi.fn();
    function Host({ open }: { open: boolean }) {
        return open ? <SelectTextSheet text={text} returnFocusTo={row} onClose={onClose} /> : null;
    }
    const { rerender } = renderWithProviders(<Host open />);

    expect(screen.getByRole('heading', { name: 'Select text' }).className).not.toContain('sr-only');
    expect(screen.getByText('Rin')).toBeTruthy();
    const body = screen.getByText((_, node) => node?.textContent === 'line one\nline two' && node.tagName === 'P');
    expect(body.className).toContain('select-text');
    expect(body.tabIndex).toBe(0);
    expect(window.getSelection()?.toString()).toBe('line one\nline two');

    fireEvent.keyDown(screen.getByRole('dialog'), { key: 'Escape' });
    expect(onClose).toHaveBeenCalled();
    rerender(<Host open={false} />);
    await act(() => new Promise((resolve) => setTimeout(resolve, 0)));
    expect(document.activeElement).toBe(row);
    row.remove();
});

test('the whole body can be copied in one press where a clipboard exists, and a withdrawn author is named as such', () => {
    const writeText = vi.fn(() => Promise.resolve());
    Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true });
    const onClose = vi.fn();
    renderWithProviders(<SelectTextSheet text={{ ...text, author: null }} returnFocusTo={null} onClose={onClose} />);

    expect(screen.getByText('Withdrawn member')).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Copy all text' }));
    expect(writeText).toHaveBeenCalledWith('line one\nline two');
    expect(onClose).toHaveBeenCalled();
});

test('the sheet stands in place rather than sliding in', () => {
    renderWithProviders(<SelectTextSheet text={text} returnFocusTo={null} onClose={vi.fn()} />);

    expect(screen.getByRole('dialog').className).not.toContain('animate-sheet');
});
