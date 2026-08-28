import { act, cleanup, fireEvent, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, expect, test, vi } from 'vitest';
import { NavDrawer } from './nav-drawer';
import { fakeT } from '@/lib/test-i18n';
import { renderWithProviders } from '@/lib/test-render';
import type { AuthUser, FeatureKey } from '@/types';

// useT reads the Inertia page for its term map, which a component test has no page to give it.
vi.mock('@/lib/i18n', () => ({ useT: () => fakeT }));

const inertia = vi.hoisted(() => ({ page: {} as { component: string; url: string; props: Record<string, unknown> } }));

vi.mock('@inertiajs/react', () => ({
    usePage: () => inertia.page,
    Link: ({ href, children, ...rest }: { href: string; children?: ReactNode }) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
    router: { visit: () => {}, post: () => {} },
}));

afterEach(cleanup);

const allOn = Object.fromEntries(
    ['diary', 'directMessage', 'timeline', 'group', 'groupTopic', 'groupEvent', 'groupTalk', 'friend', 'mcp'].map((unit) => [unit, true]),
) as Record<FeatureKey, boolean>;

const user: AuthUser = { id: 1, name: 'Viewer', email: 'viewer@example.com', imageUrl: null, avatarColor: null, isAi: false };

function arrive(look: string) {
    inertia.page = {
        component: 'dashboard',
        url: '/dashboard',
        props: {
            name: 'Test SNS',
            snsLogo: { color: '#336699', url: null },
            auth: { user },
            enabledFeatures: allOn,
            look,
            unread: { friendRequests: 0, unreadMessages: 0, notifications: 0, groupTalks: 0 },
        },
    };
}

function openDrawer(labeled = false) {
    renderWithProviders(<NavDrawer labeled={labeled} />);
    fireEvent.click(screen.getByRole('button', { name: 'Menu' }));
}

/**
 * Wiring, not values (the app-shell test's sibling): whether the drawer carries the account rows is
 * `accountInDrawer`'s question alone, and the looks whose bars drop the avatar menu must answer it
 * with rows here — read from the field, not inferred from another look-shaped boolean.
 */
test.each([
    ['standard', false],
    ['unified', true],
    ['tabbed', true],
] as const)('%s answers the account rows from its own field', (look, rows) => {
    arrive(look);
    openDrawer();

    expect(screen.queryByRole('link', { name: 'Profile' }) !== null).toBe(rows);
    expect(screen.queryByRole('button', { name: 'Sign out' }) !== null).toBe(rows);
});

/**
 * The drawer's title is the lockup, and it is a way home — the front page, not the digest that used
 * to stand there. Reached through the dialog's own naming rather than by the link's text, so the two
 * facts it carries (it names the sheet, and it goes somewhere) are checked as one.
 */
test('the drawer is named by a brand link to the front page', () => {
    arrive('standard');
    openDrawer();

    const named = screen.getByRole('dialog').getAttribute('aria-labelledby');
    const title = named === null ? null : document.getElementById(named);

    expect(title?.tagName).toBe('A');
    expect(title?.getAttribute('href')).toBe('/');
});

test('the labeled trigger opens the sheet from its own side, close control staying at the right', () => {
    arrive('tabbed');
    openDrawer(true);

    // The trigger names itself with the visible word, so no aria-label doubles it.
    const sheet = screen.getByRole('dialog');
    expect(sheet.className).toContain('right-0');
    expect(sheet.className).not.toContain('left-0');
    // Full-bleed, sliding in from the trigger's edge — the action is meant to be seen.
    expect(sheet.className).toContain('w-full');
    expect(sheet.className).toContain('animate-sheet-from-right');
    // The close control is the trigger's twin: same box, same spot, the word visible under the
    // glyph (the survey invariant is "the ✕ sits on the trigger's side" — and here, in its seat).
    const close = screen.getByRole('button', { name: 'Close' });
    expect(close.className).toContain('size-12');
    expect(close.className).toContain('right-[calc(0.5rem+env(safe-area-inset-right))]');
    expect(close.textContent).toContain('Close');
});

/**
 * Both of the tabbed look's controls carry the word under the glyph, and a tooltip over one of them
 * would float a second copy of what the reader is already looking at. The rule is per state, not per
 * component — the same trigger is icon-only in every other look, and there it does get one.
 */
test('the tabbed look shows its words, so nothing floats over them', () => {
    vi.useFakeTimers();
    arrive('tabbed');
    renderWithProviders(<NavDrawer labeled />);

    const raise = (control: HTMLElement) => {
        fireEvent.pointerMove(control, { pointerType: 'mouse' });
        control.focus();
        act(() => {
            vi.advanceTimersByTime(1000);
        });
    };

    const trigger = screen.getByRole('button', { name: 'Menu' });
    expect(trigger.textContent).toContain('Menu');
    raise(trigger);
    expect(screen.queryByRole('tooltip')).toBeNull();

    // Its twin in the sheet it opens, which spells the word the same way.
    fireEvent.click(trigger);
    const close = screen.getByRole('button', { name: 'Close' });
    expect(close.textContent).toContain('Close');
    raise(close);
    expect(screen.queryByRole('tooltip')).toBeNull();

    vi.useRealTimers();
});

test('the bare hamburger says what it is instead', () => {
    arrive('standard');
    renderWithProviders(<NavDrawer />);

    act(() => {
        screen.getByRole('button', { name: 'Menu' }).focus();
    });

    expect(screen.getByRole('tooltip').textContent).toBe('Menu');
});
