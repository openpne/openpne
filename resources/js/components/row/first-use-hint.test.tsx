import { cleanup, fireEvent, screen } from '@testing-library/react';
import { afterEach, expect, test, vi } from 'vitest';
import { FirstUseHint } from './first-use-hint';
import { fakeT } from '@/lib/test-i18n';
import { stubCoarsePointer } from '@/lib/test-pointer';
import { renderWithProviders } from '@/lib/test-render';

vi.mock('@/lib/i18n', () => ({ useT: () => fakeT }));

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

test('the hint speaks to the pointer in hand and its close button asks the page to dismiss it', () => {
    stubCoarsePointer();
    const onDismiss = vi.fn();
    renderWithProviders(<FirstUseHint visible onDismiss={onDismiss} />);

    expect(screen.getByText('Hold a row to react and more.')).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Close' }));
    expect(onDismiss).toHaveBeenCalledTimes(1);

    cleanup();
    stubCoarsePointer(false);
    renderWithProviders(<FirstUseHint visible onDismiss={onDismiss} />);
    expect(screen.getByText('Hover a row to react and more.')).toBeTruthy();
});

test('nothing is drawn once the page says the hint is gone', () => {
    const { container } = renderWithProviders(<FirstUseHint visible={false} onDismiss={vi.fn()} />);

    expect(container.innerHTML).toBe('');
});
