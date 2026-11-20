import { cleanup, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, expect, test, vi } from 'vitest';
import { MemberFrame } from './member-frame';
import { fakeT } from '@/lib/test-i18n';
import { resolveChrome } from '@/lib/member-chrome';
import type { AuthUser, FeatureKey } from '@/types';

// useT reads the Inertia page for its term map, which a component test has no page to give it.
vi.mock('@/lib/i18n', () => ({ useT: () => fakeT }));

const inertia = vi.hoisted(() => ({ page: {} as { component: string; url?: string; props: Record<string, unknown> } }));

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

const placeBar = () => screen.queryByTestId('place-bar');

/** The phone header's copy of a hub's name is hidden from assistive technology, so the page's own heading must stay on screen. */
test.each([['a member', {}], ['a guest', { auth: { user: null } }]] as const)('a hub keeps its heading on screen for %s', (_who, props) => {
    expect(hubHeadingRow(props)?.className).not.toContain('sr-only');
});

test('a month of できごと opens on its own band, so its heading is seen at desk width alone', () => {
    arrive('home/issues');

    expect(screen.getByRole('heading', { level: 1, name: 'What happened' }).parentElement?.className).toContain('max-lg:sr-only');
});

test.each([
    ['the list', 'recent'],
    ['search results', 'search'],
])("a guest's diary hub on %s keeps only the tab back to the list, and no write action", (_where, variant) => {
    arrive('diary/feed', { auth: { user: null }, variant });

    const tabs = screen.getByRole('navigation', { name: '%Diaries%' });
    expect([...tabs.querySelectorAll('a')].map((a) => a.getAttribute('href'))).toEqual(['/diary/list']);
    expect(document.querySelector('a[href="/diary/new"]')).toBeNull();
});

test("a member's diary hub keeps every lens", () => {
    arrive('diary/feed', { variant: 'recent' });

    const tabs = screen.getByRole('navigation', { name: '%Diaries%' });
    expect([...tabs.querySelectorAll('a')].map((a) => a.getAttribute('href'))).toEqual(['/diary/list', '/diary/listFriend', '/diary/listMember']);
});

test('a hub with no tab open to a guest draws no tab row for one', () => {
    arrive('community/search', { auth: { user: null } });

    expect(screen.queryByRole('navigation')).toBeNull();
});

test('the place bar is the desk answer to where the reader is: the place, as a pill with its face', () => {
    arrive('group/talk/index', { group: cyclists });

    const pill = placeBar()?.querySelector('a');
    expect(pill?.getAttribute('href')).toBe('/groups/7');
    expect(pill?.textContent).toBe('Cyclists');
    // The face the phone header cannot carry: there the brand mark is the bar's one image.
    expect(pill?.querySelector('img')?.getAttribute('src')).toBe('/img/cyclists.png');
});

test('a form says where it is without offering a way out of it', () => {
    // The rule the phone header states at its own crumb, kept at desk width: a pressable-looking
    // crumb beside unsaved input is what neither bar may paint.
    arrive('community/edit', { group: cyclists });

    expect(placeBar()?.textContent).toBe('Cyclists');
    expect(placeBar()?.querySelector('a')).toBeNull();
});

/** A guest's crumb is the phone bar's rule again at desk width: the author's entries, and no face standing for a page behind the login wall. */
test("a guest's place bar leads to the author's entries, faceless", () => {
    const author = { id: 9, name: 'Owner', imageUrl: '/img/owner.png', avatarColor: null, isAi: false };
    arrive('diary/show', { auth: { user: null }, diary: { author } });

    const pill = screen.getByRole('link', { name: "Owner's %diary%" });
    expect(placeBar()?.contains(pill)).toBe(true);
    expect(pill.getAttribute('href')).toBe('/diary/listMember/9');
    expect(placeBar()?.querySelector('img')).toBeNull();
    expect(document.querySelector('a[href="/member/9"]')).toBeNull();
});

test("a member's place bar on the same entry is the author's page, with the face", () => {
    const author = { id: 9, name: 'Owner', imageUrl: '/img/owner.png', avatarColor: null, isAi: false };
    arrive('diary/show', { diary: { author } });

    const pill = placeBar()?.querySelector('a');
    expect(pill?.getAttribute('href')).toBe('/member/9');
    expect(pill?.querySelector('img')?.getAttribute('src')).toBe('/img/owner.png');
});

test.each(['dashboard', 'notifications/index'])('%s is inside nothing, so no place bar stands over it', (component) => {
    // The sidebar's brand and its active row already answer for these; a bar repeating them would be
    // furniture with nothing to say.
    arrive(component);

    expect(placeBar()).toBeNull();
});

/**
 * The frame outlives the page under Inertia's persistent layout, so without the key a package's slot
 * would be written once per full load, not once per page as the OpenPNE 3 layout drew it.
 */
test('the main hooks follow the URL: a new page gets fresh nodes, the same page keeps them', () => {
    const page = (url: string) => {
        inertia.page = {
            component: 'notifications/index',
            url,
            props: {
                auth: { user },
                enabledFeatures: allOn,
                unread: null,
                flash: { status: null, error: null },
                layoutHooks: { 'modern.main_start': '<b>top</b>', 'modern.main_end': '<b>foot</b>' },
            },
        };
        return <MemberFrame chrome={resolveChrome('notifications/index', inertia.page.props)}>{null}</MemberFrame>;
    };
    const { container, rerender } = render(page('/notifications'));
    const start = container.querySelector('[data-layout-hook="modern.main_start"]');
    const end = container.querySelector('[data-layout-hook="modern.main_end"]');
    expect(start?.innerHTML).toBe('<b>top</b>');
    expect(end?.innerHTML).toBe('<b>foot</b>');

    rerender(page('/notifications'));
    expect(container.querySelector('[data-layout-hook="modern.main_start"]')).toBe(start);

    rerender(page('/notifications?page=2'));
    expect(container.querySelector('[data-layout-hook="modern.main_start"]')).not.toBe(start);
    expect(container.querySelector('[data-layout-hook="modern.main_end"]')).not.toBe(end);
});
