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

/** A focus the browser draws a ring for: in happy-dom every focused element is `:focus-visible`. */
test('the keyboard raises it too, and lets it go', () => {
    renderWithProviders(
        <Tip label="Reply">
            <button type="button" />
        </Tip>,
    );
    const button = screen.getByRole('button');

    act(() => {
        button.focus();
    });
    expect(screen.getByRole('tooltip').textContent).toBe('Reply');

    act(() => {
        button.blur();
    });
    expect(screen.queryByRole('tooltip')).toBeNull();
});

/**
 * The spy models a dialog moving focus by itself, onto an element the browser draws no ring for.
 */
test('a focus that moved by itself raises nothing', () => {
    renderWithProviders(
        <Tip label="Reply">
            <button type="button" />
        </Tip>,
    );
    const button = screen.getByRole('button');
    vi.spyOn(button, 'matches').mockImplementation((selector) => selector !== ':focus-visible');

    act(() => {
        button.focus();
    });

    expect(screen.queryByRole('tooltip')).toBeNull();
});
