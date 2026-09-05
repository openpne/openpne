import { cleanup, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, expect, test, vi } from 'vitest';
import { MemberFrame } from './member-frame';
import { fakeT } from '@/lib/test-i18n';
import { resolveChrome } from '@/lib/member-chrome';
import type { AuthUser, FeatureKey } from '@/types';

// useT reads the Inertia page for its term map, which a component test has no page to give it.
vi.mock('@/lib/i18n', () => ({ useT: () => fakeT }));

const inertia = vi.hoisted(() => ({ page: {} as { component: string; props: Record<string, unknown> } }));

vi.mock('@inertiajs/react', () => ({
    usePage: () => inertia.page,
    Link: ({ href, children, ...rest }: { href: string; children?: ReactNode }) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
}));

afterEach(cleanup);

const allOn = Object.fromEntries(
    ['diary', 'directMessage', 'timeline', 'group', 'groupTopic', 'groupEvent', 'groupTalk', 'friend', 'mcp'].map((unit) => [unit, true]),
) as Record<FeatureKey, boolean>;

const user: AuthUser = { id: 1, name: 'Viewer', email: 'viewer@example.com', imageUrl: null, avatarColor: null, isAi: false };

const cyclists = { id: 7, name: 'Cyclists', imageUrl: '/img/cyclists.png' };

/**
 * Land on a screen the way the layout does: the frame reads the page its chrome was resolved from.
 */
function arrive(component: string, props: Record<string, unknown> = {}) {
    inertia.page = {
        component,
        props: {
            auth: { user },
            enabledFeatures: allOn,
            look: 'standard',
            unread: null,
            flash: { status: null, error: null },
            ...props,
        },
    };

    render(<MemberFrame chrome={resolveChrome(component, inertia.page.props)}>{null}</MemberFrame>);
}

/** A hub, framed for the given viewer and layout. Returns the heading row the fold acts on. */
function hubHeadingRow(props: Record<string, unknown>): Element | null {
    arrive('notifications/index', props);

    return screen.getByRole('heading', { name: 'Notifications' }).parentElement;
}

const trail = () => screen.queryByRole('navigation', { name: 'Breadcrumb' });

const placeBar = () => screen.queryByTestId('place-bar');

/**
 * The fold is a claim that the mobile bar is showing the title instead, and only the shipped hub bar
 * does. A guest's is brand + sign-in and the unified layout's is the tab pair, so folding under
 * either leaves the hub with a visible name nowhere on a phone.
 */
test('a hub folds its heading under the bar that carries the title', () => {
    expect(hubHeadingRow({})?.className).toContain('max-lg:sr-only');
});

test('a hub keeps its heading under the unified bar, which carries tabs instead', () => {
    expect(hubHeadingRow({ look: 'unified' })?.className).not.toContain('sr-only');
});

test('a hub keeps its heading for a guest, whose bar carries no title either', () => {
    expect(hubHeadingRow({ auth: { user: null } })?.className).not.toContain('sr-only');
});

/**
 * Where a look answers "where am I" with the place bar, that is the whole answer: the trail it
 * replaces must not stand beside it, or the desk shows the same claim twice in two vocabularies.
 */
test('the place bar stands in place of the trail, not beside it', () => {
    arrive('group/talk/index', { look: 'tabbed', group: cyclists });

    const pill = placeBar()?.querySelector('a');
    expect(pill?.getAttribute('href')).toBe('/groups/7');
    expect(pill?.textContent).toBe('Cyclists');
    // The face the phone header cannot carry: there the brand mark is the bar's one image.
    expect(pill?.querySelector('img')?.getAttribute('src')).toBe('/img/cyclists.png');
    expect(trail()).toBeNull();
});

test.each(['standard', 'unified'] as const)('%s keeps the crumb trail it has always drawn', (look) => {
    arrive('group/talk/index', { look, group: cyclists });

    expect(trail()?.className).toContain('hidden lg:flex');
    expect(trail()?.textContent).toBe('Cyclists');
    expect(placeBar()).toBeNull();
});

test('a form says where it is without offering a way out of it', () => {
    // The rule the phone header states at its own crumb, kept at desk width: a pressable-looking
    // crumb beside unsaved input is what neither bar may paint.
    arrive('community/edit', { look: 'tabbed', group: cyclists });

    expect(placeBar()?.textContent).toBe('Cyclists');
    expect(placeBar()?.querySelector('a')).toBeNull();
});

test.each(['dashboard', 'notifications/index'])('%s is inside nothing, so no place bar stands over it', (component) => {
    // The sidebar's brand and its active row already answer for these; a bar repeating them would be
    // furniture with nothing to say.
    arrive(component, { look: 'tabbed' });

    expect(placeBar()).toBeNull();
});
