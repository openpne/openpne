import { cleanup, screen } from '@testing-library/react';
import { afterEach, expect, test, vi } from 'vitest';
import { ReactionChipsRow } from './reaction-bar';
import { fakeT } from '@/lib/test-i18n';
import { renderWithProviders } from '@/lib/test-render';

vi.mock('@/lib/i18n', () => ({ useT: () => fakeT }));

afterEach(cleanup);

const chips = [{ emoji: '\u{1F44D}', count: 2, mine: false }];

test('a reader who may react gets the chips as toggles', () => {
    renderWithProviders(<ReactionChipsRow chips={chips} onToggle={vi.fn()} />);

    expect(screen.getByRole('button', { name: /2/, pressed: false })).toBeTruthy();
});

test('a reader who may not react gets counts and nothing to press', () => {
    renderWithProviders(<ReactionChipsRow chips={chips} />);

    expect(screen.getByText('2')).toBeTruthy();
    expect(screen.queryAllByRole('button')).toHaveLength(0);
});

test('a row with no reactions draws no chip row, whoever reads it', () => {
    const { container } = renderWithProviders(<ReactionChipsRow chips={[]} onToggle={vi.fn()} />);

    expect(container.querySelector('[data-reactions]')).toBeNull();
});
