import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, expect, test, vi } from 'vitest';
import { MemberTile } from './member-tile';
import { fakeT } from '@/lib/test-i18n';

// useT reads the Inertia page for its term map, which a component test has no page to give it.
vi.mock('@/lib/i18n', () => ({ useT: () => fakeT }));

afterEach(cleanup);

const member = { id: 7, name: 'Shirabe', imageUrl: null, avatarColor: null, isAi: false };

/**
 * The accessible name is the assertion, not the presence of a chip: a marker that lands outside the
 * name leaves a screen reader unable to tell an AI account from a person. axe does not catch that —
 * the name is present and non-empty either way.
 */
test('an AI account is named as one by the tile link', () => {
    render(<MemberTile member={{ ...member, isAi: true }} />);

    expect(screen.getByRole('link').textContent).toContain('AI');
    expect(screen.getByRole('link', { name: 'Shirabe AI' })).toBeTruthy();
});

test("a person's tile link is named by their name alone", () => {
    render(<MemberTile member={member} />);

    expect(screen.getByRole('link', { name: 'Shirabe' })).toBeTruthy();
    expect(screen.getByRole('link').textContent).not.toContain('AI');
});

test('the tile links to the profile whatever the account is', () => {
    render(<MemberTile member={{ ...member, isAi: true }} />);

    expect(screen.getByRole('link').getAttribute('href')).toBe('/member/7');
});
