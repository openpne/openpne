import { act, cleanup, fireEvent, screen } from '@testing-library/react';
import { afterEach, expect, test, vi } from 'vitest';
import { Lightbox } from './lightbox';
import { fakeT } from '@/lib/test-i18n';
import { renderWithProviders } from '@/lib/test-render';

vi.mock('@/lib/i18n', () => ({ useT: () => fakeT }));

afterEach(cleanup);

const images = [{ url: '/a.png' }, { url: '/b.png' }, { url: '/c.png' }];

function open(index: number | null, set = images, restoreFocus?: () => void) {
    const onClose = vi.fn();
    const onNavigate = vi.fn();
    const ui = (at: number | null) => <Lightbox images={set} index={at} onClose={onClose} onNavigate={onNavigate} restoreFocus={restoreFocus} />;
    const view = renderWithProviders(ui(index));

    return { onClose, onNavigate, rerender: (at: number | null) => view.rerender(ui(at)) };
}

const dialog = () => screen.queryByRole('dialog');

const activeSlide = () => document.querySelector<HTMLElement>('[data-active]');

test('closed until an index is given; then the dialog shows where it stands in the set and where the image lives', () => {
    const { rerender } = open(null);
    expect(dialog()).toBeNull();

    rerender(1);
    expect(dialog()).toBeTruthy();
    expect(screen.getByText('2 / 3')).toBeTruthy();
    expect(screen.getByRole('link', { name: 'Open in new tab' }).getAttribute('href')).toBe('/b.png');
    expect(activeSlide()?.querySelector('img')?.getAttribute('src')).toBe('/b.png');
});

test('the arrow keys step through the set and stop at its ends', () => {
    const { onNavigate, rerender } = open(1);

    fireEvent.keyDown(dialog()!, { key: 'ArrowRight' });
    expect(onNavigate).toHaveBeenLastCalledWith(2);
    fireEvent.keyDown(dialog()!, { key: 'ArrowLeft' });
    expect(onNavigate).toHaveBeenLastCalledWith(0);
    expect(screen.getByRole('button', { name: 'Next image' }).getAttribute('aria-disabled')).toBe('false');

    rerender(2);
    onNavigate.mockClear();
    fireEvent.keyDown(dialog()!, { key: 'ArrowRight' });
    fireEvent.click(screen.getByRole('button', { name: 'Next image' }));
    expect(onNavigate).not.toHaveBeenCalled();
    expect(screen.getByRole('button', { name: 'Next image' }).getAttribute('aria-disabled')).toBe('true');

    fireEvent.click(screen.getByRole('button', { name: 'Previous image' }));
    expect(onNavigate).toHaveBeenLastCalledWith(1);
});

test('a single image has nothing to step through and no dots', () => {
    open(0, [images[0]!]);

    expect(dialog()).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'Next image' })).toBeNull();
    expect(screen.queryByText('1 / 1')).toBeNull();
});

test('the close button, Escape and a click on the letterbox close it; the image itself and a touch do not', () => {
    const { onClose } = open(0);

    fireEvent.click(screen.getByRole('button', { name: 'Close' }));
    expect(onClose).toHaveBeenCalledTimes(1);

    fireEvent.keyDown(dialog()!, { key: 'Escape' });
    expect(onClose).toHaveBeenCalledTimes(2);

    const slide = activeSlide()!;
    fireEvent(slide.querySelector('img')!, new PointerEvent('click', { bubbles: true, pointerType: 'mouse' }));
    fireEvent(slide, new PointerEvent('click', { bubbles: true, pointerType: 'touch' }));
    expect(onClose).toHaveBeenCalledTimes(2);

    fireEvent(slide, new PointerEvent('click', { bubbles: true, pointerType: 'mouse' }));
    expect(onClose).toHaveBeenCalledTimes(3);
});

test('closing hands focus back to what opened it', async () => {
    const restoreFocus = vi.fn();
    const { rerender } = open(0, images, restoreFocus);

    rerender(null);
    // Radix returns focus from a zero-delay timer after the content is gone.
    await act(() => new Promise((resolve) => setTimeout(resolve, 0)));

    expect(dialog()).toBeNull();
    expect(restoreFocus).toHaveBeenCalledTimes(1);
});
