import { act, cleanup, fireEvent, render, screen } from '@testing-library/react';
import type { ReactNode, RefObject } from 'react';
import { afterEach, expect, test, vi } from 'vitest';
import { AppShell } from './app-shell';
import { useComposerEngaged } from '@/components/compose/compose-sheet-action';
import { fakeT } from '@/lib/test-i18n';
import { type Chrome, resolveChrome } from '@/lib/member-chrome';
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

// The shell's other furniture. None of it is what these tests are about, and each brings its own
// page reads and effects; the two bars stay real, since where they stand is the subject.
vi.mock('@/components/left-nav', () => ({ LeftNav: () => null }));
// A marker rather than null: whether the shell seats the rail at all is one of the wiring answers.
vi.mock('@/components/right-rail', () => ({ RightRail: () => <div data-testid="right-rail" /> }));
vi.mock('@/components/look-preview-bar', () => ({ LookPreviewBar: () => null }));
vi.mock('@/components/unread-sync', () => ({ UnreadSync: () => null }));
vi.mock('@/components/confirm-dialog', () => ({ ConfirmDialogHost: () => null }));
vi.mock('@/components/action-fab', () => ({ ActionFab: () => null }));

afterEach(cleanup);

const allOn = Object.fromEntries(
    ['diary', 'directMessage', 'timeline', 'group', 'groupTopic', 'groupEvent', 'groupTalk', 'friend', 'mcp'].map((unit) => [unit, true]),
) as Record<FeatureKey, boolean>;

const user: AuthUser = { id: 1, name: 'Viewer', email: 'viewer@example.com', imageUrl: null, avatarColor: null, isAi: false };

const cyclists = { id: 7, name: 'Cyclists', imageUrl: null };

function arrive(component: string, url: string, props: Record<string, unknown> = {}): Chrome {
    inertia.page = {
        component,
        url,
        props: {
            name: 'Test SNS',
            snsLogo: { color: '#336699', url: null },
            auth: { user },
            enabledFeatures: allOn,
            look: 'standard',
            lookPreview: null,
            unread: { friendRequests: 0, unreadMessages: 0, notifications: 0, groupTalks: 0 },
            ...props,
        },
    };

    return resolveChrome(component, inertia.page.props);
}

/**
 * A composer that reports its focus the way the conversation composers do.
 *
 * A div stands in for their `<form>`, and only because of the runner: happy-dom hands out a Proxy
 * for a form element whose `contains()` answers false for its own children (`div.contains()` is
 * fine), which would fail the containment check here for a reason no browser has. The mechanics
 * under test — where the listeners go, and what `relatedTarget` means — are the element's, not the
 * tag's.
 */
function StubComposer() {
    const form = useComposerEngaged();

    return (
        <div ref={form as unknown as RefObject<HTMLDivElement>}>
            <textarea aria-label="Message" />
            <button type="button">Attach</button>
        </div>
    );
}

function room(look: string) {
    const chrome = arrive('group/talk/index', '/groups/7/talk', { look, group: cyclists });
    const { container } = render(
        <AppShell chrome={chrome}>
            <StubComposer />
        </AppShell>,
    );

    return container.firstElementChild as HTMLElement;
}

const bar = () => document.querySelector('nav[aria-label="Navigation"]');

const header = () => document.querySelector('header');

/**
 * Wiring, not values: several LookSpec fields carry identical value vectors (rightRail and
 * foldsHubHeading; colorLine, placeBar and bottomBarInConversation), so a registry-value test can
 * never catch a consumer reading the wrong one. What the shell actually renders per look can.
 */
test.each([
    // Null = no desktop override at all: the unified bar stands at every width, so the phone's
    // reserved height is the desktop's too. The two that drop it differ by what is left above the
    // page — nothing, or the site-color line.
    ['standard', { ground: false, rail: true, line: false, desktopOffset: 'lg:[--modern-top-offset:0px]', width: 'max-w-6xl xl:max-w-7xl' }],
    ['unified', { ground: true, rail: false, line: false, desktopOffset: null, width: 'max-w-6xl lg:max-w-[58rem]' }],
    ['tabbed', { ground: true, rail: false, line: true, desktopOffset: 'lg:[--modern-top-offset:4px]', width: 'max-w-6xl lg:max-w-[58rem]' }],
] as const)('%s wires ground, rail, the color line and the desktop offset from its own fields', (look, expected) => {
    const chrome = arrive('dashboard', '/dashboard', { look });
    const { container } = render(<AppShell chrome={chrome}>page</AppShell>);

    expect(document.documentElement.classList.contains('unified')).toBe(expected.ground);
    expect(screen.queryByTestId('right-rail') !== null).toBe(expected.rail);
    const line = screen.queryByTestId('site-color-line');
    expect(line !== null).toBe(expected.line);
    const shell = container.firstElementChild as HTMLElement;
    // The whole declaration, not merely its presence: an offset that stopped clearing the line would
    // otherwise pass as long as some `lg:` value was there.
    expect(/lg:\[--modern-top-offset:[^\]]+\]/.exec(shell.className)?.[0] ?? null).toBe(expected.desktopOffset);
    // Rail-less looks shrink the frame to sidebar + content so the pair centers as one block —
    // the retired rail's width must not survive as a dead band beside the page.
    expect(shell.className.match(/(?:[\w.]+:)?max-w-\S+/g)?.join(' ')).toBe(expected.width);
});

test('the desktop line is the site color, and only desktop draws it', () => {
    const chrome = arrive('dashboard', '/dashboard', { look: 'tabbed' });
    render(<AppShell chrome={chrome}>page</AppShell>);
    const line = screen.getByTestId('site-color-line');

    // Inline, because the color is per-site data — no palette class can carry it.
    expect(line.style.backgroundColor).toBe('#336699');
    // The phone's copy is the breadcrumb bar's own foot, which is why this one is hidden below lg.
    expect(line.className).toContain('hidden');
    expect(line.className).toContain('lg:block');
});

/**
 * The shipped answer, unchanged: a conversation draws no bottom bar at all, so its composer is the
 * foot of the screen from the moment the room opens and nothing about focus moves anything.
 */
test('a conversation under the shipped look has no bottom bar, written in or not', () => {
    const shell = room('standard');

    expect(bar()).toBeNull();
    expect(shell.className).toContain('[--modern-bottom-offset:env(safe-area-inset-bottom)]');

    fireEvent.focusIn(screen.getByLabelText('Message'));

    expect(bar()).toBeNull();
    expect(shell.className).toContain('[--modern-bottom-offset:env(safe-area-inset-bottom)]');
});

test('a tabbed conversation keeps its bar standing while the room is read', () => {
    const shell = room('tabbed');

    expect(bar()).not.toBeNull();
    expect(bar()?.className).not.toContain('translate-y-full');
    // The taller labelled row, reserved once: the composer's own padding is what lifts it clear, so
    // a disagreement here would either double the safe-area inset or seat the bar over the message.
    expect(shell.className).toContain('[--modern-bottom-offset:calc(3.625rem+1px+env(safe-area-inset-bottom))]');
});

test('writing takes the bar away and gives its space back, without unmounting it', () => {
    const shell = room('tabbed');

    fireEvent.focusIn(screen.getByLabelText('Message'));

    // Still in the document: it slides out over the same 200ms the composer takes to descend, which
    // an unmount would replace with a blink.
    expect(bar()).not.toBeNull();
    expect(bar()?.className).toContain('translate-y-full');
    expect(shell.className).toContain('[--modern-bottom-offset:env(safe-area-inset-bottom)]');
});

test('an accessory tap inside the composer is not leaving it', () => {
    room('tabbed');
    const field = screen.getByLabelText('Message');

    fireEvent.focusIn(field);
    // What a field-level blur would read as leaving: focus moved to the button beside it, inside the
    // same form. The bar must not flap out and back on every attach or send.
    fireEvent.focusOut(field, { relatedTarget: screen.getByRole('button', { name: 'Attach' }) });

    expect(bar()?.className).toContain('translate-y-full');

    fireEvent.focusOut(field, { relatedTarget: null });

    expect(bar()?.className).not.toContain('translate-y-full');
});

/**
 * One listener for the whole chrome, so a room's two bands cannot fall out of step: reading down a
 * long backlog takes both away, and one scroll up brings both back.
 */
test('a tabbed conversation recedes header and bar together', async () => {
    Object.defineProperty(window, 'scrollY', { value: 0, configurable: true, writable: true });
    room('tabbed');

    expect(header()?.className).not.toContain('-translate-y-full');

    await act(async () => {
        (window as { scrollY: number }).scrollY = 400;
        window.dispatchEvent(new Event('scroll'));
        await new Promise((resolve) => setTimeout(resolve, 50));
    });

    expect(header()?.className).toContain('-translate-y-full');
    expect(bar()?.className).toContain('translate-y-full');
});

test('a conversation under the shipped look holds its chrome still', async () => {
    Object.defineProperty(window, 'scrollY', { value: 0, configurable: true, writable: true });
    room('standard');

    await act(async () => {
        (window as { scrollY: number }).scrollY = 400;
        window.dispatchEvent(new Event('scroll'));
        await new Promise((resolve) => setTimeout(resolve, 50));
    });

    expect(header()?.className).not.toContain('-translate-y-full');
});
