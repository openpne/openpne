import { cleanup, fireEvent, screen } from '@testing-library/react';
import { afterEach, expect, test, vi } from 'vitest';
import { ActionSheet } from './action-sheet';
import { fakeT } from '@/lib/test-i18n';
import { renderWithProviders } from '@/lib/test-render';

vi.mock('@/lib/i18n', () => ({ useT: () => fakeT }));

afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
});

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

test('a sheet rises from the foot of the screen unless told to stand in place', () => {
    open(false);
    expect(screen.getByRole('dialog').className).toContain('animate-sheet-from-bottom');

    cleanup();
    renderWithProviders(
        <ActionSheet open animated={false} onOpenChange={vi.fn()} title="Post actions" returnFocusTo={{ current: null }}>
            <span>content</span>
        </ActionSheet>,
    );
    expect(screen.getByRole('dialog').className).not.toContain('animate-sheet');
});
