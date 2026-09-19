import { cleanup, screen } from '@testing-library/react';
import { afterEach, expect, test, vi } from 'vitest';
import { RowReactionChips } from './reaction-bar';
import { fakeT } from '@/lib/test-i18n';
import { renderWithProviders } from '@/lib/test-render';

vi.mock('@/lib/i18n', () => ({ useT: () => fakeT }));

afterEach(cleanup);

const chips = [{ emoji: '\u{1F44D}', count: 2, mine: false }];

test('a reader who may react gets the chips as toggles, the add button and the reactor list', () => {
    renderWithProviders(<RowReactionChips reactions={{ chips, vocabulary: ['\u{1F44D}'], onToggle: vi.fn(), onShowReactors: vi.fn() }} />);

    expect(screen.getByRole('button', { name: /2/ })).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Add a reaction' })).toBeTruthy();
    expect(screen.getByRole('button', { name: 'See who reacted' })).toBeTruthy();
});

test('a reader who may not react gets counts and nothing to press', () => {
    renderWithProviders(<RowReactionChips reactions={{ chips, vocabulary: ['\u{1F44D}'] }} />);

    expect(screen.getByText('2')).toBeTruthy();
    expect(screen.queryAllByRole('button')).toHaveLength(0);
});

test('a row with nothing on it and no reader who may react draws nothing', () => {
    const { container } = renderWithProviders(<RowReactionChips reactions={{ chips: [], vocabulary: ['\u{1F44D}'] }} />);

    expect(container.querySelector('[data-reactions]')).toBeNull();
});
