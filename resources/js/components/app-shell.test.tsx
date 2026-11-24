import { act, cleanup, createEvent, fireEvent, screen } from '@testing-library/react';
import { type ReactNode, type RefObject, useRef } from 'react';
import { afterEach, expect, test, vi } from 'vitest';
import { AppShell } from './app-shell';
import { useComposerEngaged } from '@/components/compose/compose-sheet-action';
import { fakeT } from '@/lib/test-i18n';
import { renderWithProviders } from '@/lib/test-render';
import { type Chrome, resolveChrome } from '@/lib/member-chrome';
import { useDropTarget } from '@/lib/use-drop-target';
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
    router: { visit: () => {}, post: () => {}, on: () => () => {} },
}));

// Mocked because each brings its own page reads and effects; the two bars stay real, since where
// they stand is the subject.
vi.mock('@/components/left-nav', () => ({ LeftNav: () => null }));
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
            unread: { friendRequests: 0, unreadMessages: 0, notifications: 0, groupTalks: 0 },
            ...props,
        },
    };

    return resolveChrome(component, inertia.page.props);
}

/**
 * A div stands in for the composers' `<form>` because of the runner: happy-dom hands out a Proxy for
 * a form element whose `contains()` answers false for its own children, which no browser does. The
 * mechanics under test — where the listeners go, and what `relatedTarget` means — are the element's.
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

function room() {
    const chrome = arrive('group/talk/index', '/groups/7/talk', { group: cyclists });
    const { container } = renderWithProviders(
        <AppShell chrome={chrome}>
            <StubComposer />
        </AppShell>,
    );

    return container.firstElementChild as HTMLElement;
}

const bar = () => document.querySelector('nav[aria-label="Navigation"]');

const header = () => document.querySelector('header');

/** The offsets are whole declarations, not merely present: one that stopped clearing the line would otherwise pass as long as some value was there. */
test('the shell paints the one ground, the color line and the offsets that clear them', () => {
    const chrome = arrive('home/issue', '/');
    const { container } = renderWithProviders(<AppShell chrome={chrome}>page</AppShell>);

    expect(document.documentElement.classList.contains('member-ground')).toBe(true);
    expect(screen.queryByTestId('site-color-line')).not.toBeNull();
    const shell = container.firstElementChild as HTMLElement;
    expect(shell.className).toContain('[--modern-top-offset:calc(3rem+4px+env(safe-area-inset-top))]');
    expect(/lg:\[--modern-top-offset:[^\]]+\]/.exec(shell.className)?.[0] ?? null).toBe('lg:[--modern-top-offset:4px]');
    expect(/lg:\[--modern-place-bar:[^\]]+\]/.exec(shell.className)?.[0] ?? null).toBe(null);
    // The frame is sidebar + content: a width left over from the retired third column would stand
    // as a dead band beside the page.
    expect(shell.className.match(/(?:[\w.]+:)?max-w-\S+/g)?.join(' ')).toBe('max-w-6xl lg:max-w-[61rem]');
    expect(shell.className).toContain('[--modern-bottom-offset:calc(3.625rem+1px+env(safe-area-inset-bottom))]');
});

test.each([
    ['group/talk/index', '/groups/7/talk', 'lg:[--modern-place-bar:calc(3.5rem+1px)]'],
    ['group/show', '/groups/7', null],
    ['member/show', '/member/9', null],
    // A form inside no place names its context in the bar, and the editor's toolbar stands below that bar.
    ['diary/new', '/diary/new', 'lg:[--modern-place-bar:calc(3.5rem+1px)]'],
    // A hub names itself in the bar, as the phone header does.
    ['notifications/index', '/notifications', 'lg:[--modern-place-bar:calc(3.5rem+1px)]'],
    ['home/issue', '/', null],
] as const)('%s publishes the place bar height only where the bar stands', (component, url, expected) => {
    const chrome = arrive(component, url, { group: cyclists, profile: { id: 9, name: 'Owner', imageUrl: null, avatarColor: null, isAi: false } });
    const { container } = renderWithProviders(<AppShell chrome={chrome}>page</AppShell>);

    const shell = container.firstElementChild as HTMLElement;
    expect(/lg:\[--modern-place-bar:[^\]]+\]/.exec(shell.className)?.[0] ?? null).toBe(expected);
});

/**
 * The bar's height is written in bottom-nav.tsx and reserved again here, and the two disagreeing is
 * not a visible bug but a permanent one, so this reads the reserved length back off the shell and
 * requires the row that stands in it to be exactly that tall.
 */
test('the shell reserves the length the tab row stands at', () => {
    const chrome = arrive('dashboard', '/dashboard');
    const { container } = renderWithProviders(<AppShell chrome={chrome}>page</AppShell>);

    const shell = container.firstElementChild as HTMLElement;
    const reserved = /\[--modern-bottom-offset:calc\(([^+]+)\+1px/.exec(shell.className)?.[1];
    const row = bar()?.querySelector('ul');

    expect(reserved).toBeTruthy();
    expect(row).not.toBeNull();
    expect(row?.className.split(' ')).toContain(`h-[${reserved}]`);
});

test('the desktop line is the site color, and only desktop draws it', () => {
    const chrome = arrive('dashboard', '/dashboard');
    renderWithProviders(<AppShell chrome={chrome}>page</AppShell>);
    const line = screen.getByTestId('site-color-line');

    // The color is per-site data, so it travels as a custom property the class reads.
    expect(line.style.getPropertyValue('--site-color')).toBe('#336699');
    expect(line.className).toContain('bg-(--site-color)');
    // The phone's copy is the phone header's own foot, which is why this one is hidden below lg.
    expect(line.className).toContain('hidden');
    expect(line.className).toContain('lg:block');
});

test('a conversation keeps its bar standing while the room is read', () => {
    const shell = room();

    expect(bar()).not.toBeNull();
    expect(bar()?.className).not.toContain('translate-y-full');
    // The taller labelled row, reserved once: the composer's own padding is what lifts it clear, so
    // a disagreement here would either double the safe-area inset or seat the bar over the message.
    expect(shell.className).toContain('[--modern-bottom-offset:calc(3.625rem+1px+env(safe-area-inset-bottom))]');
});

test('writing takes the bar away and gives its space back, without unmounting it', () => {
    const shell = room();

    fireEvent.focusIn(screen.getByLabelText('Message'));

    // Still in the document: it slides out over the same 200ms the composer takes to descend, which
    // an unmount would replace with a blink.
    expect(bar()).not.toBeNull();
    expect(bar()?.className).toContain('translate-y-full');
    expect(shell.className).toContain('[--modern-bottom-offset:env(safe-area-inset-bottom)]');
});

test('an accessory tap inside the composer is not leaving it', () => {
    room();
    const field = screen.getByLabelText('Message');

    fireEvent.focusIn(field);
    // What a field-level blur would read as leaving: focus moved to a button inside the same form.
    fireEvent.focusOut(field, { relatedTarget: screen.getByRole('button', { name: 'Attach' }) });

    expect(bar()?.className).toContain('translate-y-full');

    fireEvent.focusOut(field, { relatedTarget: null });

    expect(bar()?.className).not.toContain('translate-y-full');
});

test("pressing the room's name while writing leaves the composer, so the bar comes back", () => {
    room();
    const field = screen.getByLabelText('Message');
    fireEvent.focusIn(field);
    expect(bar()?.className).toContain('translate-y-full');

    const name = document.querySelector<HTMLElement>('header [data-place-trigger="phone"]')!;
    fireEvent.focusOut(field, { relatedTarget: name });
    fireEvent.click(name);

    expect(name.getAttribute('aria-expanded')).toBe('true');
    expect(bar()?.className).not.toContain('translate-y-full');
});

/**
 * One listener for the whole chrome, so a room's two bands cannot fall out of step: reading down a
 * long backlog takes both away, and one scroll up brings both back.
 */
test('a conversation holds its chrome still through a scroll', async () => {
    // Only writing takes the bar away: the composer pins the screen's foot, so hiding it frees nothing.
    Object.defineProperty(window, 'scrollY', { value: 0, configurable: true, writable: true });
    room();

    await act(async () => {
        window.dispatchEvent(new Event('wheel'));
        (window as { scrollY: number }).scrollY = 400;
        window.dispatchEvent(new Event('scroll'));
        await new Promise((resolve) => requestAnimationFrame(() => resolve(undefined)));
    });

    expect(header()?.className).not.toContain('-translate-y-full');
    expect(bar()?.className).not.toContain('translate-y-full');
});

// Plain shapes: the test renderer copies an init's own properties onto a fresh DataTransfer, and a real one keeps its types behind getters.
const filesDrag = { types: ['Files'], files: [new File([new Uint8Array(4)], 'a.png', { type: 'image/png' })] };

test('a picture dropped outside any target is swallowed, so the page and its draft stay', () => {
    const chrome = arrive('dashboard', '/dashboard', {});
    renderWithProviders(
        <AppShell chrome={chrome}>
            <p>page</p>
            <input type="file" aria-label="Picture" />
            <textarea aria-label="Title" />
            <input type="checkbox" aria-label="Remove" />
            <textarea aria-label="Frozen" readOnly />
        </AppShell>,
    );
    const offAPage = { ...filesDrag, types: ['text/uri-list', 'text/html', 'Files'] };

    // Prevented at the window: the browser would otherwise open the file in the tab.
    expect(fireEvent.dragOver(screen.getByText('page'), { dataTransfer: filesDrag })).toBe(false);
    expect(fireEvent.drop(screen.getByText('page'), { dataTransfer: filesDrag })).toBe(false);
    // A picture dragged off a page carries a file too, and would open over the draft the same way,
    // except on a field, which keeps the browser's own insert of the link's text.
    expect(fireEvent.drop(screen.getByText('page'), { dataTransfer: offAPage })).toBe(false);
    expect(fireEvent.drop(screen.getByLabelText('Title'), { dataTransfer: offAPage })).toBe(true);
    expect(fireEvent.drop(screen.getByLabelText('Title'), { dataTransfer: filesDrag })).toBe(false);
    // A control that takes no text would navigate to the link instead.
    expect(fireEvent.drop(screen.getByLabelText('Remove'), { dataTransfer: offAPage })).toBe(false);
    expect(fireEvent.drop(screen.getByLabelText('Frozen'), { dataTransfer: offAPage })).toBe(false);
    // A file input keeps its native drop, and a drag of text is not the guard's business.
    expect(fireEvent.drop(screen.getByLabelText('Picture'), { dataTransfer: filesDrag })).toBe(true);
    expect(fireEvent.drop(screen.getByText('page'), { dataTransfer: { types: ['text/plain'], files: [] } })).toBe(true);
});

function DropStub() {
    const field = useRef<HTMLDivElement>(null);
    useDropTarget(field, { onFiles: () => {} });

    return (
        <form>
            <div ref={field}>
                <textarea aria-label="Body" />
            </div>
        </form>
    );
}

test('over a target the shell leaves the drag to it: the copy effect the target set survives the guard', () => {
    const chrome = arrive('dashboard', '/dashboard', {});
    renderWithProviders(
        <AppShell chrome={chrome}>
            <DropStub />
        </AppShell>,
    );
    const body = screen.getByLabelText('Body');

    const over = createEvent.dragOver(body, { dataTransfer: filesDrag });
    fireEvent(body, over);

    expect(over.defaultPrevented).toBe(true);
    // The guard would set `none`, and a browser then drops nothing on the target.
    expect((over as DragEvent).dataTransfer?.dropEffect).toBe('copy');
});
