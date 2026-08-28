import { afterEach, beforeEach, expect, test, vi } from 'vitest';
import { installScrollResetSettle } from './scroll-reset-settle';

/** The wiring, under happy-dom: a fake router hands over its listeners, and the window's scroll is a variable. */
function install() {
    const listeners: Record<string, Array<() => void>> = {};
    const settler = installScrollResetSettle({
        on(type, callback) {
            (listeners[type] ??= []).push(callback);

            return () => {};
        },
    });
    const fire = (type: string) => listeners[type]?.forEach((callback) => callback());

    return { settler, fire };
}

let y = 0;
let scrollTo: ReturnType<typeof vi.spyOn>;

beforeEach(() => {
    y = 0;
    Object.defineProperty(window, 'scrollY', { configurable: true, get: () => y });
    scrollTo = vi.spyOn(window, 'scrollTo').mockImplementation(() => {
        y = 0;
    });
});

afterEach(() => {
    // Every installed settler listens for this and lets go of its hold, so no test answers another's scroll.
    window.dispatchEvent(new Event('popstate'));
    history.replaceState(null, '', '/');
    scrollTo.mockRestore();
});

/** The bounce iOS was seen to deliver: the window reporting itself off the top after the reset. */
const bounce = () => {
    y = 90;
    window.dispatchEvent(new Event('scroll'));
};

const answered = () => scrollTo.mock.calls.some(([x, top]) => x === 0 && top === 0);

test('a bounce after an arrival Inertia reset is answered with the top again', () => {
    const { settler, fire } = install();

    settler.expect();
    fire('success');
    bounce();

    expect(answered()).toBe(true);
    expect(y).toBe(0);
});

test('an arrival that kept its scroll is left alone', () => {
    const { fire } = install();

    fire('success');
    bounce();

    expect(answered()).toBe(false);
});

test('a hash URL is scrolled to its anchor by Inertia, not held at the top', () => {
    const { settler, fire } = install();
    history.replaceState(null, '', '/#comment-3');

    settler.expect();
    fire('success');
    bounce();

    expect(answered()).toBe(false);
});

test('a popstate ends the hold, so the restore it brings is not undone', () => {
    const { settler, fire } = install();

    settler.expect();
    fire('success');
    window.dispatchEvent(new Event('popstate'));
    bounce();

    expect(answered()).toBe(false);
});

test("the reader's first input ends the hold", () => {
    const { settler, fire } = install();

    settler.expect();
    fire('success');
    window.dispatchEvent(new Event('touchstart'));
    bounce();

    expect(answered()).toBe(false);
});

test('the hold ends with its window', () => {
    vi.useFakeTimers();
    const { settler, fire } = install();

    settler.expect();
    fire('success');
    vi.advanceTimersByTime(1000);
    bounce();

    expect(answered()).toBe(false);
    vi.useRealTimers();
});
