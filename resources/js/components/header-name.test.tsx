import { cleanup, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, expect, test, vi } from 'vitest';
import { MemberFrame } from './member-frame';
import { TopNav } from './top-nav';
import { fakeT } from '@/lib/test-i18n';
import { renderWithProviders } from '@/lib/test-render';
import { resolveChrome } from '@/lib/member-chrome';
import type { AuthUser, FeatureKey } from '@/types';

vi.mock('@/lib/i18n', () => ({ useT: () => fakeT }));

const inertia = vi.hoisted(() => ({ page: {} as { component: string; url: string; props: Record<string, unknown> } }));

vi.mock('@inertiajs/react', () => ({
    usePage: () => inertia.page,
    Link: ({ href, children, ...rest }: { href: string; children?: ReactNode }) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
    router: { visit: () => {}, on: () => () => {} },
}));

afterEach(cleanup);

const allOn = Object.fromEntries(
    ['diary', 'directMessage', 'timeline', 'group', 'groupTopic', 'groupEvent', 'groupTalk', 'friend', 'mcp'].map((unit) => [unit, true]),
) as Record<FeatureKey, boolean>;

const user: AuthUser = { id: 1, name: 'Viewer', email: 'viewer@example.com', imageUrl: null, avatarColor: null, isAi: false };

// Pictures on both, so a face's fallback initials cannot pass for part of the name.
const cyclists = { id: 7, name: 'Cyclists', imageUrl: '/img/cyclists.png' };
const author = { id: 9, name: 'Owner', imageUrl: '/img/owner.png', avatarColor: null, isAi: false };

/** The phone header and the frame for one page, mounted together as the shell mounts them at every width. */
function arrive(component: string, props: Record<string, unknown>) {
    inertia.page = {
        component,
        url: '/',
        props: {
            name: 'Test SNS',
            snsLogo: { color: '#336699', url: null },
            auth: { user },
            enabledFeatures: allOn,
            unread: { friendRequests: 0, unreadMessages: 0, notifications: 0, groupTalks: 0 },
            flash: { status: null, error: null },
            ...props,
        },
    };
    const chrome = resolveChrome(component, inertia.page.props);

    renderWithProviders(
        <>
            <TopNav chrome={chrome} />
            <MemberFrame chrome={chrome}>{null}</MemberFrame>
        </>,
    );
}

/** The name as seen and heard: its words, whether it can be pressed, and whether assistive technology is kept from it. */
const named = (element: Element | null | undefined) => ({
    words: element?.textContent,
    tag: element?.tagName,
    hidden: element?.getAttribute('aria-hidden') ?? null,
});

test.each([
    ['a hub', 'notifications/index', {}],
    ['a past day', 'home/archive', { issue: { monthHref: '/home/2026/08' } }],
    ['a settings form', 'member/config/email', {}],
    ['a screen inside a group', 'group/talk/index', { group: cyclists }],
    ["a guest inside an author's place", 'diary/show', { auth: { user: null }, diary: { author } }],
])('%s is given the same name by the phone header and the desk bar', (_what, component, props) => {
    arrive(component, props);

    const phone = document.querySelector('header > div')?.children[1];
    const desk = screen.getByTestId('place-bar').firstElementChild;

    expect(named(phone).words).not.toBe('');
    expect(named(desk)).toEqual(named(phone));
});
