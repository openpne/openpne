import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, expect, test } from 'vitest';
import { RadioCardGroup } from './field';

afterEach(cleanup);

function describedBy(): string[] {
    const group = screen.getByRole('group');

    return (group.getAttribute('aria-describedby') ?? '').split(' ').filter(Boolean);
}

test('a description is announced with the group', () => {
    render(
        <RadioCardGroup legend="Display" description="Applies to desktop browsers.">
            <input type="radio" name="x" />
        </RadioCardGroup>,
    );

    const ids = describedBy();
    expect(ids).toHaveLength(1);
    expect(document.getElementById(ids[0] ?? '')?.textContent).toBe('Applies to desktop browsers.');
});

test('the description and the error are both referenced', () => {
    render(
        <RadioCardGroup legend="Display" description="Applies to desktop browsers." error="Pick one.">
            <input type="radio" name="x" />
        </RadioCardGroup>,
    );

    const texts = describedBy().map((id) => document.getElementById(id)?.textContent);
    expect(texts).toEqual(['Applies to desktop browsers.', 'Pick one.']);
});

test('without a description nothing is referenced', () => {
    render(
        <RadioCardGroup legend="Display">
            <input type="radio" name="x" />
        </RadioCardGroup>,
    );

    expect(screen.getByRole('group').hasAttribute('aria-describedby')).toBe(false);
});
