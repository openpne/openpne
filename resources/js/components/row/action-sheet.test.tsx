import { cleanup, fireEvent, screen } from '@testing-library/react';
import { afterEach, expect, test, vi } from 'vitest';
import { ActionSheet } from './action-sheet';
import { fakeT } from '@/lib/test-i18n';
import { renderWithProviders } from '@/lib/test-render';

vi.mock('@/lib/i18n', () => ({ useT: () => fakeT }));

afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
    delete (HTMLElement.prototype as { getAnimations?: unknown }).getAnimations;
});

const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

function open(openedByPress: boolean) {
    const onChoose = vi.fn();
    renderWithProviders(
        <ActionSheet open openedByPress={openedByPress} onOpenChange={vi.fn()} title="Post actions" returnFocusTo={{ current: null }}>
            <button type="button" onClick={onChoose}>
                choose
            </button>
        </ActionSheet>,
    );

    return onChoose;
}

test('the click the opening press lands on release is not a choice; a later tap is', () => {
    const clock = vi.spyOn(performance, 'now').mockReturnValue(1000);
    const onChoose = open(true);
    const item = screen.getByRole('button', { name: 'choose' });

    fireEvent.pointerUp(document.body);
    fireEvent.click(item);
    expect(onChoose).not.toHaveBeenCalled();

    clock.mockReturnValue(1500);
    fireEvent.pointerDown(item);
    fireEvent.pointerUp(item);
    fireEvent.click(item);
    expect(onChoose).toHaveBeenCalledTimes(1);
});

test('a sheet opened by a button takes every click', () => {
    const onChoose = open(false);

    fireEvent.pointerUp(document.body);
    fireEvent.click(screen.getByRole('button', { name: 'choose' }));
    expect(onChoose).toHaveBeenCalledTimes(1);
});

test('what the sheet runs on opening waits for its slide-in to end, and is dropped if the sheet is cancelled first', async () => {
    let finish: (() => void) | undefined;
    let cancel: ((reason: unknown) => void) | undefined;
    const finished = new Promise<void>((resolve) => {
        finish = resolve;
    });
    Object.defineProperty(HTMLElement.prototype, 'getAnimations', { value: () => [{ finished }], configurable: true });
    const onOpened = vi.fn();
    renderWithProviders(
        <ActionSheet open onOpened={onOpened} onOpenChange={vi.fn()} title="Post actions" returnFocusTo={{ current: null }}>
            <span>content</span>
        </ActionSheet>,
    );

    expect(onOpened).not.toHaveBeenCalled();
    finish?.();
    await settle();
    expect(onOpened).toHaveBeenCalledTimes(1);

    cleanup();
    const again = vi.fn();
    const cancelled = new Promise<void>((_, reject) => {
        cancel = reject;
    });
    Object.defineProperty(HTMLElement.prototype, 'getAnimations', { value: () => [{ finished: cancelled }], configurable: true });
    renderWithProviders(
        <ActionSheet open onOpened={again} onOpenChange={vi.fn()} title="Post actions" returnFocusTo={{ current: null }}>
            <span>content</span>
        </ActionSheet>,
    );
    cancel?.(new DOMException('cancelled', 'AbortError'));
    await settle();
    expect(again).not.toHaveBeenCalled();
});

test('with no animation to wait for, what the sheet runs on opening runs at once', () => {
    const onOpened = vi.fn();
    renderWithProviders(
        <ActionSheet open onOpened={onOpened} onOpenChange={vi.fn()} title="Post actions" returnFocusTo={{ current: null }}>
            <span>content</span>
        </ActionSheet>,
    );

    expect(onOpened).toHaveBeenCalledTimes(1);
});
