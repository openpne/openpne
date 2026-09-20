import { act, cleanup, fireEvent, screen } from '@testing-library/react';
import { useState } from 'react';
import { afterEach, expect, test, vi } from 'vitest';
import { ConfirmDialogHost, useConfirm } from './confirm-dialog';
import { fakeT } from '@/lib/test-i18n';
import { renderWithProviders } from '@/lib/test-render';

vi.mock('@/lib/i18n', () => ({ useT: () => fakeT }));

afterEach(cleanup);

function Asker({ onAnswer }: { onAnswer: (ok: boolean) => void }) {
    const confirm = useConfirm();

    return (
        <button type="button" onClick={() => void confirm({ title: 'Delete this?' }).then(onAnswer)}>
            ask
        </button>
    );
}

test.each([
    ['Cancel', false],
    ['OK', true],
])('the question answers %s and gives focus back to what asked it', async (choice, answer) => {
    const onAnswer = vi.fn();
    renderWithProviders(
        <>
            <Asker onAnswer={onAnswer} />
            <ConfirmDialogHost />
        </>,
    );
    const asker = screen.getByRole('button', { name: 'ask' });
    asker.focus();
    fireEvent.click(asker);
    expect(await screen.findByRole('alertdialog')).toBeTruthy();

    fireEvent.click(screen.getByRole('button', { name: choice }));
    await act(() => new Promise((resolve) => setTimeout(resolve, 0)));

    expect(onAnswer).toHaveBeenCalledWith(answer);
    expect(screen.queryByRole('alertdialog')).toBeNull();
    expect(document.activeElement).toBe(asker);
});

test('asked with an opener, the question returns focus there even when what asked is gone by then', async () => {
    const row = document.createElement('li');
    row.tabIndex = -1;
    document.body.append(row);

    function Asker() {
        const confirm = useConfirm();
        const [asked, setAsked] = useState(false);

        // The asker leaves as the sheet's item does: the question is asked, then what asked it unmounts.
        return asked ? null : (
            <button
                type="button"
                onClick={() => {
                    void confirm({ title: 'Delete this?', opener: row });
                    setAsked(true);
                }}
            >
                ask
            </button>
        );
    }
    renderWithProviders(
        <>
            <Asker />
            <ConfirmDialogHost />
        </>,
    );
    const asker = screen.getByRole('button', { name: 'ask' });
    asker.focus();
    fireEvent.click(asker);
    expect(await screen.findByRole('alertdialog')).toBeTruthy();
    expect(asker.isConnected).toBe(false);

    fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
    await act(() => new Promise((resolve) => setTimeout(resolve, 0)));
    expect(document.activeElement).toBe(row);
    row.remove();
});
