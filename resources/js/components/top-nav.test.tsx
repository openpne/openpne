import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, expect, test, vi } from 'vitest';
import { ScopeIdentity } from './top-nav';
import { fakeT } from '@/lib/test-i18n';
import type { ChromeScope } from '@/lib/member-chrome';

// useT reads the Inertia page for its term map, which a component test has no page to give it.
vi.mock('@/lib/i18n', () => ({ useT: () => fakeT }));

afterEach(cleanup);

const memberScope: ChromeScope = { kind: 'member', id: 7, name: 'Shirabe', imageUrl: null, avatarColor: null, isAi: false };

/**
 * The bar's scope is the member the page is *about* — a diary's author, a DM counterparty — not the
 * viewer, so it can be an AI account. Its avatar is decorative and there is no room beside a
 * truncating name for a chip, which leaves the link's accessible name as the only place the fact fits.
 */
test('an AI scope is named as one', () => {
    render(<ScopeIdentity scope={{ ...memberScope, isAi: true }} />);

    expect(screen.getByRole('link', { name: 'Shirabe (AI)' })).toBeTruthy();
});

test("a person's scope is named by their name alone", () => {
    render(<ScopeIdentity scope={memberScope} />);

    expect(screen.getByRole('link', { name: 'Shirabe' })).toBeTruthy();
});

test('a group scope is named by the group, unmarked', () => {
    render(<ScopeIdentity scope={{ kind: 'group', id: 3, name: 'Book club', imageUrl: null }} />);

    expect(screen.getByRole('link', { name: 'Book club' })).toBeTruthy();
});

test('the scope block still links to whoever it names', () => {
    render(<ScopeIdentity scope={{ ...memberScope, isAi: true }} />);

    expect(screen.getByRole('link').getAttribute('href')).toBe('/member/7');
});
