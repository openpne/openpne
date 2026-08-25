import { act, cleanup, fireEvent, screen } from '@testing-library/react';
import { afterEach, expect, test, vi } from 'vitest';
import { Tip } from './tooltip';
import { renderWithProviders } from '@/lib/test-render';

afterEach(() => {
    cleanup();
    // In an afterEach, not at the foot of each test: a test that fails mid-body would otherwise leak
    // its fake timers or its DEV stub into the next one.
    vi.useRealTimers();
    vi.unstubAllEnvs();
});

/** Past any provider delay: what these tests are about is what a raised panel says, not when. */
function hover(element: HTMLElement) {
    fireEvent.pointerMove(element, { pointerType: 'mouse' });
    act(() => {
        vi.advanceTimersByTime(1000);
    });
}

test('the label names the control it wraps', () => {
    renderWithProviders(
        <Tip label="Reply">
            <button type="button" />
        </Tip>,
    );

    expect(screen.getByRole('button', { name: 'Reply' })).toBeTruthy();
});

/**
 * The whole reason the label is cloned in rather than merged through Slot: outside dev nothing
 * throws, and the injected name has to be the one that survives — otherwise a child's stale
 * `aria-label` would quietly disagree with the panel beside it.
 */
test('the label wins over a name the child brought, where nothing throws', () => {
    vi.stubEnv('DEV', false);

    renderWithProviders(
        <Tip label="Reply">
            <button type="button" aria-label="Stale" />
        </Tip>,
    );

    expect(screen.getByRole('button', { name: 'Reply' })).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'Stale' })).toBeNull();
});

test('a child that brought its own name fails in dev', () => {
    expect(() =>
        renderWithProviders(
            <Tip label="Reply">
                <button type="button" aria-label="Stale" />
            </Tip>,
        ),
    ).toThrow(/aria-label/);
});

test('a child with no name of its own passes the guard', () => {
    expect(() =>
        renderWithProviders(
            <Tip label="Reply">
                <button type="button" />
            </Tip>,
        ),
    ).not.toThrow();
});

test("the child's own description is left where it points", () => {
    vi.useFakeTimers();
    renderWithProviders(
        <>
            <p id="hint">Only your friends can see this.</p>
            <Tip label="Reply">
                <button type="button" aria-describedby="hint" />
            </Tip>
        </>,
    );
    const button = screen.getByRole('button', { name: 'Reply' });

    expect(button.getAttribute('aria-describedby')).toBe('hint');
    hover(button);
    expect(button.getAttribute('aria-describedby')).toBe('hint');
});

/**
 * Open or shut, the control is named once and described by nothing: the panel repeats the name, and
 * a description carrying it again reads out as "Reply, button, Reply".
 */
test('the raised panel is not announced a second time as a description', () => {
    vi.useFakeTimers();
    renderWithProviders(
        <Tip label="Reply">
            <button type="button" />
        </Tip>,
    );
    const button = screen.getByRole('button', { name: 'Reply' });

    expect(button.getAttribute('aria-describedby')).toBeNull();

    hover(button);

    expect(screen.getByRole('tooltip').textContent).toBe('Reply');
    expect(screen.getByRole('button', { name: 'Reply' })).toBeTruthy();
    expect(button.getAttribute('aria-describedby')).toBeNull();
});

test('a pointer raises the panel', () => {
    vi.useFakeTimers();
    renderWithProviders(
        <Tip label="Reply">
            <button type="button" />
        </Tip>,
    );
    const button = screen.getByRole('button');

    expect(screen.queryByRole('tooltip')).toBeNull();
    hover(button);
    expect(screen.getByRole('tooltip').textContent).toBe('Reply');
});

/** `silent` is for a control that shows its word in one state and not the other: the name stays. */
test('a silent Tip names the control and floats nothing', () => {
    vi.useFakeTimers();
    renderWithProviders(
        <Tip label="Write" silent>
            <button type="button">Write</button>
        </Tip>,
    );
    const button = screen.getByRole('button', { name: 'Write' });

    hover(button);

    expect(screen.queryByRole('tooltip')).toBeNull();
});

test('the keyboard raises it too, and lets it go', () => {
    renderWithProviders(
        <Tip label="Reply">
            <button type="button" />
        </Tip>,
    );
    const button = screen.getByRole('button');

    act(() => {
        fireEvent.focus(button);
    });
    expect(screen.getByRole('tooltip').textContent).toBe('Reply');

    act(() => {
        fireEvent.blur(button);
    });
    expect(screen.queryByRole('tooltip')).toBeNull();
});
