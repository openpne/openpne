import { act, cleanup, fireEvent, screen, waitFor, within } from '@testing-library/react';
import type { MouseEventHandler, ReactNode } from 'react';
import { afterEach, beforeEach, expect, test, vi } from 'vitest';
import { PlaceSwitch } from './place-switch';
import type { ChromeScope } from '@/lib/member-chrome';
import { fakeT } from '@/lib/test-i18n';
import { renderWithProviders } from '@/lib/test-render';

vi.mock('@/lib/i18n', () => ({ useT: () => fakeT }));

// The tiles are the router's links; the stand-in keeps the anchor and lets a test see whether the
// click was let through as a visit.
const inertia = vi.hoisted(() => ({
    component: 'group/talk/index',
    navigate: [] as (() => void)[],
    visits: [] as string[],
}));

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ component: inertia.component, props: {} }),
    Link: ({ href, children, onClick, ...rest }: { href: string; children?: ReactNode; onClick?: MouseEventHandler<HTMLAnchorElement> }) => (
        <a
            href={href}
            {...rest}
            onClick={(event) => {
                onClick?.(event);
                if (!event.defaultPrevented) {
                    inertia.visits.push(href);
                }
                event.preventDefault();
            }}
        >
            {children}
        </a>
    ),
    router: {
        on: (type: string, listener: () => void) => {
            if (type === 'navigate') {
                inertia.navigate.push(listener);
            }

            return () => {
                inertia.navigate = inertia.navigate.filter((each) => each !== listener);
            };
        },
    },
}));

/** One query list for `lg`, flipped by the test as a resize would. */
const desk = vi.hoisted(() => ({ matches: false, listeners: [] as (() => void)[] }));

beforeEach(() => {
    inertia.component = 'group/talk/index';
    inertia.visits = [];
    desk.matches = false;
    desk.listeners = [];
    vi.stubGlobal('matchMedia', (query: string) => ({
        get matches() {
            return query === '(min-width: 64rem)' ? desk.matches : false;
        },
        media: query,
        addEventListener: (_type: string, listener: () => void) => desk.listeners.push(listener),
        removeEventListener: (_type: string, listener: () => void) => {
            desk.listeners = desk.listeners.filter((each) => each !== listener);
        },
    }));
});

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

const cyclists = (section: 'top' | 'talk' | 'topic' | 'event' | 'members' = 'talk', open = { talk: true, topic: true, event: true }): ChromeScope => ({
    kind: 'group',
    id: 7,
    name: 'Cyclists',
    imageUrl: null,
    section,
    open,
});

const trigger = (variant: 'phone' | 'desk' = 'phone') => document.querySelector<HTMLButtonElement>(`[data-place-trigger="${variant}"]`)!;

const panel = () => screen.queryByRole('dialog', { name: 'Cyclists' });

function openOn(variant: 'phone' | 'desk' = 'phone') {
    fireEvent.click(trigger(variant));

    return panel()!;
}

test('the name says whether the sections are open, and opening lands on the current section', () => {
    renderWithProviders(<PlaceSwitch scope={cyclists()} variant="phone" />);
    expect(trigger().getAttribute('aria-expanded')).toBe('false');

    const sections = openOn();

    expect(trigger().getAttribute('aria-expanded')).toBe('true');
    const talk = within(sections).getByRole('link', { name: 'Talk' });
    expect(talk.getAttribute('aria-current')).toBe('page');
    expect(document.activeElement).toBe(talk);
    expect(within(sections).getAllByRole('link').map((link) => link.getAttribute('href'))).toEqual([
        '/groups/7',
        '/groups/7/talk',
        '/groups/7/topics',
        '/groups/7/events',
        '/groups/7/members',
    ]);
});

test('a screen deeper inside a section marks it as current, not as the page', () => {
    inertia.component = 'group/topic/show';
    renderWithProviders(<PlaceSwitch scope={cyclists('topic')} variant="phone" />);

    const topics = within(openOn()).getByRole('link', { name: '%Topics%' });

    expect(topics.getAttribute('aria-current')).toBe('true');
    expect(document.activeElement).toBe(topics);
});

test('Esc closes the sections and gives focus back to the name', async () => {
    renderWithProviders(<PlaceSwitch scope={cyclists()} variant="phone" />);
    openOn();

    fireEvent.keyDown(document.activeElement!, { key: 'Escape' });

    await waitFor(() => expect(panel()).toBeNull());
    expect(trigger().getAttribute('aria-expanded')).toBe('false');
    expect(document.activeElement).toBe(trigger());
});

test('pressing outside closes the sections', async () => {
    renderWithProviders(<PlaceSwitch scope={cyclists()} variant="phone" />);
    openOn();

    // Radix listens for a press outside from the task after the panel mounts, and waits for the click
    // that ends a primary press.
    await act(() => new Promise((resolve) => setTimeout(resolve, 0)));
    fireEvent.pointerDown(document.body);
    fireEvent.click(document.body);

    await waitFor(() => expect(panel()).toBeNull());
    expect(document.activeElement).toBe(trigger());
});

test('the tile of the page itself only closes, and focus goes back to the name', async () => {
    renderWithProviders(<PlaceSwitch scope={cyclists()} variant="phone" />);

    fireEvent.click(within(openOn()).getByRole('link', { name: 'Talk' }));

    await waitFor(() => expect(panel()).toBeNull());
    expect(inertia.visits).toEqual([]);
    expect(document.activeElement).toBe(trigger());
});

test('another tile visits its section and closes without pulling focus back to the name', async () => {
    renderWithProviders(<PlaceSwitch scope={cyclists()} variant="phone" />);

    fireEvent.click(within(openOn()).getByRole('link', { name: 'Top page' }));

    await waitFor(() => expect(panel()).toBeNull());
    expect(inertia.visits).toEqual(['/groups/7']);
    expect(document.activeElement).not.toBe(trigger());
});

test('a page swapped in by the browser closes the sections, without pulling focus back to the name', async () => {
    renderWithProviders(<PlaceSwitch scope={cyclists()} variant="phone" />);
    openOn();

    act(() => inertia.navigate.forEach((listener) => listener()));

    await waitFor(() => expect(panel()).toBeNull());
    expect(document.activeElement).not.toBe(trigger());
});

test('a page swapped in while the sections are shut leaves the next close its usual focus', async () => {
    renderWithProviders(<PlaceSwitch scope={cyclists()} variant="phone" />);
    act(() => inertia.navigate.forEach((listener) => listener()));
    openOn();

    fireEvent.keyDown(document.activeElement!, { key: 'Escape' });

    await waitFor(() => expect(panel()).toBeNull());
    expect(document.activeElement).toBe(trigger());
});

test('a tile pressed with a modifier is left to the browser and keeps the sections open', () => {
    renderWithProviders(<PlaceSwitch scope={cyclists()} variant="phone" />);

    fireEvent.click(within(openOn()).getByRole('link', { name: 'Top page' }), { ctrlKey: true });

    expect(panel()).not.toBeNull();
});

test('while open, a sheet over the page takes every press outside the panel', async () => {
    renderWithProviders(<PlaceSwitch scope={cyclists()} variant="phone" />);
    expect(document.querySelector('[data-place-backdrop]')).toBeNull();

    openOn();
    const sheet = document.querySelector<HTMLElement>('[data-place-backdrop]');
    // A page control can opt back into pointer events under Radix's modal, so the sheet must too.
    expect(sheet?.className).toContain('pointer-events-auto');
    expect(sheet?.className).toContain('fixed inset-0');
    // Above the header and the talk's jump-to-latest pill, both z-20.
    expect(sheet?.className).toContain('z-40');
    expect(panel()!.contains(sheet)).toBe(false);

    await act(() => new Promise((resolve) => setTimeout(resolve, 0)));
    fireEvent.pointerDown(sheet!);
    fireEvent.click(sheet!);

    await waitFor(() => expect(panel()).toBeNull());
    expect(document.querySelector('[data-place-backdrop]')).toBeNull();
});

test('a board the viewer may not open is not offered', () => {
    renderWithProviders(<PlaceSwitch scope={cyclists('top', { talk: false, topic: false, event: false })} variant="phone" />);

    expect(within(openOn()).getAllByRole('link').map((link) => link.textContent)).toEqual(['Top page', 'Members']);
});

test('the phone name and the desk name open apart: opening one leaves the other shut', () => {
    renderWithProviders(
        <>
            <PlaceSwitch scope={cyclists()} variant="phone" />
            <PlaceSwitch scope={cyclists()} variant="desk" />
        </>,
    );

    openOn('desk');

    expect(trigger('desk').getAttribute('aria-expanded')).toBe('true');
    expect(trigger('phone').getAttribute('aria-expanded')).toBe('false');
    expect(screen.getAllByRole('dialog')).toHaveLength(1);
});

test('crossing lg with the sections open closes them and puts focus on the name now on screen', async () => {
    desk.matches = true;
    renderWithProviders(
        <>
            <PlaceSwitch scope={cyclists()} variant="phone" />
            <PlaceSwitch scope={cyclists()} variant="desk" />
        </>,
    );
    openOn('desk');

    desk.matches = false;
    act(() => desk.listeners.forEach((listener) => listener()));

    await waitFor(() => expect(panel()).toBeNull());
    await waitFor(() => expect(document.activeElement).toBe(trigger('phone')));
});

test('the name now on screen takes focus only once the receded header has come back', async () => {
    desk.matches = true;
    renderWithProviders(
        <>
            <header inert>
                <PlaceSwitch scope={cyclists()} variant="phone" />
            </header>
            <PlaceSwitch scope={cyclists()} variant="desk" />
        </>,
    );
    openOn('desk');
    // Frames run only when the test says, so the wait's budget cannot run out under a loaded runner.
    const frames: FrameRequestCallback[] = [];
    vi.stubGlobal('requestAnimationFrame', (callback: FrameRequestCallback) => frames.push(callback));
    vi.stubGlobal('cancelAnimationFrame', () => {});
    const frame = () => act(() => frames.splice(0).forEach((callback) => callback(performance.now())));

    desk.matches = false;
    act(() => desk.listeners.forEach((listener) => listener()));
    await waitFor(() => expect(panel()).toBeNull());
    frame();
    frame();
    frame();
    expect(document.activeElement).not.toBe(trigger('phone'));

    trigger('phone').closest('header')!.removeAttribute('inert');
    frame();

    expect(document.activeElement).toBe(trigger('phone'));
});
