import { act, cleanup, fireEvent, screen } from '@testing-library/react';
import { afterEach, expect, test, vi } from 'vitest';
import { SelectTextSheet } from './select-text-sheet';
import { fakeT } from '@/lib/test-i18n';
import { renderWithProviders } from '@/lib/test-render';

vi.mock('@/lib/i18n', () => ({ useT: () => fakeT }));

afterEach(cleanup);

test('the body stands alone, selectable, and closing gives focus back to the row it came from', async () => {
    const row = document.createElement('li');
    row.tabIndex = -1;
    document.body.append(row);
    const onClose = vi.fn();
    function Host({ open }: { open: boolean }) {
        return open ? <SelectTextSheet body={'line one\nline two'} returnFocusTo={row} onClose={onClose} /> : null;
    }
    const { rerender } = renderWithProviders(<Host open />);

    const body = screen.getByText((_, node) => node?.textContent === 'line one\nline two' && node.tagName === 'P');
    expect(body.className).toContain('select-text');

    fireEvent.keyDown(screen.getByRole('dialog'), { key: 'Escape' });
    expect(onClose).toHaveBeenCalled();
    rerender(<Host open={false} />);
    await act(() => new Promise((resolve) => setTimeout(resolve, 0)));
    expect(document.activeElement).toBe(row);
    row.remove();
});
