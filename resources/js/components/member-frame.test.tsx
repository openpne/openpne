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
    router: { on: () => () => {} },
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

test.each([['a member', {}], ['a guest', { auth: { user: null } }]] as const)('a hub keeps its heading for assistive technology alone below lg, for %s', (_who, props) => {
    expect(hubHeadingRow(props)?.className).toContain('max-lg:sr-only');
});

test('a month of issues is a hub like any other, its heading drawn at desk width alone', () => {
    arrive('home/issues');

    expect(screen.getByRole('heading', { level: 1, name: 'What happened' }).parentElement?.className).toContain('max-lg:sr-only');
});

test('a screen inside a place keeps its heading on screen at both widths', () => {
    arrive('diary/list', { owner: { id: 9, name: 'Owner', imageUrl: null, avatarColor: null, isAi: false }, isOwner: false });

    expect(screen.getByRole('heading', { level: 1, name: '%Diaries%' }).parentElement?.className).not.toContain('sr-only');
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

test("the place bar is the desk answer to where the reader is: the group's face and name, which open its sections", () => {
    arrive('group/talk/index', { group: cyclists });

    const name = screen.getByRole('button', { name: 'Cyclists' });
    expect(placeBar()?.contains(name)).toBe(true);
    expect(name.getAttribute('aria-expanded')).toBe('false');
    // The face the phone header cannot carry: there the brand mark is the bar's one image.
    expect(name.querySelector('img')?.getAttribute('src')).toBe('/img/cyclists.png');
    expect(placeBar()?.querySelector('a')).toBeNull();
});

test("a group's top stands no place bar: its hero names the group", () => {
    arrive('group/show', { group: cyclists });

    expect(placeBar()).toBeNull();
});

test('a form says where it is without offering a way out of it', () => {
    arrive('community/edit', { group: cyclists });

    expect(placeBar()?.textContent).toBe('Cyclists');
    expect(placeBar()?.querySelector('a, button')).toBeNull();
});

test('a screen inside no place names its section at desk width too, as text', () => {
    arrive('timeline/tag', { tag: 'ride' });

    expect(placeBar()?.textContent).toBe('%Activity%');
    expect(placeBar()?.querySelector('a, button')).toBeNull();
});

test.each(['home/archive', 'policy/show'])('%s stands no place bar: the page names itself directly below', (component) => {
    arrive(component, { issue: { monthHref: '/home/2026/08' }, kind: 'terms' });

    expect(placeBar()).toBeNull();
});

/** The phone bar's rule again at desk width: no face standing for a page behind the login wall. */
test("a guest's place bar names the author, faceless and with nothing to press", () => {
    const author = { id: 9, name: 'Owner', imageUrl: '/img/owner.png', avatarColor: null, isAi: false };
    arrive('diary/show', { auth: { user: null }, diary: { author } });

    expect(placeBar()?.textContent).toBe('Owner');
    expect(placeBar()?.querySelector('a, button, img')).toBeNull();
});

test("a member's place bar on the same entry opens the author's sections, with the face", () => {
    const author = { id: 9, name: 'Owner', imageUrl: '/img/owner.png', avatarColor: null, isAi: false };
    arrive('diary/show', { diary: { author } });

    const trigger = placeBar()?.querySelector('button');
    expect(trigger?.textContent).toBe('Owner');
    expect(trigger?.getAttribute('aria-expanded')).toBe('false');
    expect(trigger?.querySelector('img')?.getAttribute('src')).toBe('/img/owner.png');
    expect(placeBar()?.querySelector('a')).toBeNull();
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
