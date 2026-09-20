import { cleanup, fireEvent, screen } from '@testing-library/react';
import { afterEach, expect, test, vi } from 'vitest';
import { SelectTextSheet } from './select-text-sheet';
import { fakeT } from '@/lib/test-i18n';
import { renderWithProviders } from '@/lib/test-render';

vi.mock('@/lib/i18n', () => ({ useT: () => fakeT }));

afterEach(cleanup);

test('the body stands alone, selectable, and closing gives focus back to the row it came from', () => {
    const row = document.createElement('li');
    row.tabIndex = -1;
    document.body.append(row);
    const onClose = vi.fn();
    renderWithProviders(<SelectTextSheet body={'line one\nline two'} returnFocusTo={row} onClose={onClose} />);

    const body = screen.getByText((_, node) => node?.textContent === 'line one\nline two' && node.tagName === 'P');
    expect(body.className).toContain('select-text');

    fireEvent.keyDown(screen.getByRole('dialog'), { key: 'Escape' });
    expect(onClose).toHaveBeenCalled();
    row.remove();
});
