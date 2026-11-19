import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, expect, test, vi } from 'vitest';
import { CrumbText } from './crumb-text';
import { fakeT } from '@/lib/test-i18n';

vi.mock('@/lib/i18n', () => ({ useT: () => fakeT }));

afterEach(cleanup);

const visibleParts = (container: HTMLElement) => Array.from(container.querySelectorAll('[aria-hidden] > span')).map((part) => [part.textContent, part.className]);

test('out of room, the name is what gives way and the words after it stay whole', () => {
    const { container } = render(<CrumbText label={{ key: ":name's %diary%", replacements: { name: 'A very long member name' } }} />);

    expect(visibleParts(container)).toEqual([
        ['A very long member name', 'truncate'],
        ["'s %diary%", 'shrink-0 whitespace-pre'],
    ]);
    // Read as one phrase, not as the pieces the layout cuts it into.
    expect(screen.getByText("A very long member name's %diary%").className).toBe('sr-only');
});

test('the phrase read aloud is the one on screen, whatever the name holds', () => {
    const { container } = render(<CrumbText label={{ key: ":name's %diary%", replacements: { name: 'Rin $& :NAME %Diary%' } }} />);

    const shown = Array.from(container.querySelectorAll('[aria-hidden] > span')).map((part) => part.textContent).join('');
    expect(shown).toBe("Rin $& :NAME %Diary%'s %diary%");
    expect(container.querySelector('.sr-only')?.textContent).toBe(shown);
});

test('words before the name stay whole too, whatever order a translation puts them in', () => {
    const { container } = render(<CrumbText label={{ key: '%Diary% of :name', replacements: { name: 'Rin' } }} />);

    expect(visibleParts(container)).toEqual([
        ['%Diary% of ', 'shrink-0 whitespace-pre'],
        ['Rin', 'truncate'],
    ]);
});

test.each([
    ['a name alone', 'Cyclists'],
    ['a label with no name in it', { key: 'Settings' }],
])('%s truncates as one run', (_what, label) => {
    const { container } = render(<CrumbText label={label} />);

    expect(container.querySelector('[aria-hidden]')).toBeNull();
    expect(container.firstElementChild?.className).toContain('truncate');
});
