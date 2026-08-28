import { act, cleanup, render, screen } from '@testing-library/react';
import { afterEach, expect, test, vi } from 'vitest';
import { useScrollDirection } from './use-scroll-direction';

const inertia = vi.hoisted(() => ({ url: '/a', listeners: {} as Record<string, Array<() => void>> }));
vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ url: inertia.url }),
    router: {
        on: (type: string, callback: () => void) => {
            (inertia.listeners[type] ??= []).push(callback);

            return () => {
                inertia.listeners[type] = inertia.listeners[type].filter((listener) => listener !== callback);
            };
        },
    },
}));

function Probe() {
    return <output>{useScrollDirection()}</output>;
}

let y = 0;
Object.defineProperty(window, 'scrollY', { configurable: true, get: () => y });

afterEach(() => {
    cleanup();
    y = 0;
    inertia.url = '/a';
    inertia.listeners = {};
});

/** A visit has landed: Inertia has set the page and, for one that resets, scrolled it to the top. */
const arrived = async (at: number) => {
    await scrolled(at);
    await act(() => inertia.listeners.success?.forEach((listener) => listener()));
};

/** The browser (or the reader, once engaged) has scrolled the window to `to`. */
const scrolled = async (to: number) => {
    await act(async () => {
        y = to;
        window.dispatchEvent(new Event('scroll'));
        await new Promise((resolve) => requestAnimationFrame(() => resolve(undefined)));
    });
};

const touched = () => act(() => window.dispatchEvent(new Event('touchstart')));

const direction = () => screen.getByRole('status').textContent;

test('a scroll the reader did not make leaves the chrome; the same scroll after a touch takes it', async () => {
    render(<Probe />);

    await scrolled(400);
    expect(direction()).toBe('up');

    await touched();
    await scrolled(420);
    expect(direction()).toBe('down');
});

test("the reader's travel is measured from where the page was when they took it", async () => {
    render(<Probe />);

    // The bounce: the page sits at 60 through nobody's doing.
    await scrolled(60);
    await touched();
    // 4 more is under the threshold from 60, though far past it from the top.
    await scrolled(64);
    expect(direction()).toBe('up');
    await scrolled(70);
    expect(direction()).toBe('down');
});

test('a new page starts with no reader again', async () => {
    const view = render(<Probe />);

    await touched();
    await scrolled(400);
    expect(direction()).toBe('down');

    inertia.url = '/b';
    view.rerender(<Probe />);
    await scrolled(400);
    expect(direction()).toBe('up');
});

test('an arrival at the same URL comes back under the gate', async () => {
    render(<Probe />);

    await touched();
    await scrolled(400);
    expect(direction()).toBe('down');

    // The active tab tapped again: Inertia resets the scroll and fires no navigate, and the URL is
    // the same. The bounce that follows is not the reader's.
    await arrived(0);
    await scrolled(400);
    expect(direction()).toBe('up');
});

test('the reader can take the page again after it came back under the gate', async () => {
    render(<Probe />);

    await touched();
    await scrolled(400);
    await arrived(0);
    await scrolled(400);
    expect(direction()).toBe('up');

    await touched();
    await scrolled(420);
    expect(direction()).toBe('down');
});

test('a reload landing while the reader is down the page keeps their travel', async () => {
    render(<Probe />);

    await touched();
    await scrolled(400);
    await arrived(400);
    expect(direction()).toBe('down');

    await scrolled(380);
    expect(direction()).toBe('up');
});

