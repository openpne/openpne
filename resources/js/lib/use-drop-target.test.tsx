import { cleanup, fireEvent, render } from '@testing-library/react';
import { useRef } from 'react';
import { afterEach, expect, test, vi } from 'vitest';
import { useDropTarget } from './use-drop-target';

afterEach(cleanup);

const picture = () => new File([new Uint8Array(4)], 'a.png', { type: 'image/png' });

// Plain shapes: the test renderer copies an init's own properties onto a fresh DataTransfer, and a real one keeps its files behind getters.
const withFiles = (files: File[]) => ({ types: ['Files'], files, getData: () => '' });
const withText = (text: string, files: File[] = []) => ({ types: ['text/plain'], files, getData: (type: string) => (type === 'text/plain' ? text : '') });

function Target({ onFiles, enabled = true, paste = false }: { onFiles: (files: File[]) => void; enabled?: boolean; paste?: boolean }) {
    const field = useRef<HTMLDivElement>(null);
    const dragging = useDropTarget(field, { onFiles, enabled, paste });

    return (
        <form data-testid="form" data-dragging={dragging}>
            <div ref={field}>
                <span data-testid="child">child</span>
            </div>
        </form>
    );
}

test('the form around the field is the target; a drop with pictures hands them on and ends the drag', () => {
    const onFiles = vi.fn();
    const { getByTestId } = render(<Target onFiles={onFiles} />);
    const form = getByTestId('form');
    const dataTransfer = withFiles([picture()]);

    fireEvent.dragEnter(form, { dataTransfer });
    expect(form.dataset.dragging).toBe('true');
    // Crossing into a child fires enter before leave; the count keeps the ring up.
    fireEvent.dragEnter(getByTestId('child'), { dataTransfer });
    fireEvent.dragLeave(form, { dataTransfer });
    expect(form.dataset.dragging).toBe('true');

    fireEvent.drop(getByTestId('child'), { dataTransfer });
    expect(onFiles).toHaveBeenCalledWith([expect.objectContaining({ name: 'a.png' })]);
    expect(form.dataset.dragging).toBe('false');
});

test('a drag of text is left to the browser and shows no ring', () => {
    const onFiles = vi.fn();
    const { getByTestId } = render(<Target onFiles={onFiles} />);
    const form = getByTestId('form');
    const dataTransfer = withText('words');

    const enter = fireEvent.dragEnter(form, { dataTransfer });
    const drop = fireEvent.drop(form, { dataTransfer });

    expect(form.dataset.dragging).toBe('false');
    expect(onFiles).not.toHaveBeenCalled();
    // Not prevented: the browser keeps its default for a text drag.
    expect(enter).toBe(true);
    expect(drop).toBe(true);
});

test('while disabled a drop is swallowed without being handed on', () => {
    const onFiles = vi.fn();
    const { getByTestId } = render(<Target onFiles={onFiles} enabled={false} />);
    const form = getByTestId('form');
    const dataTransfer = withFiles([picture()]);

    fireEvent.dragEnter(form, { dataTransfer });
    expect(form.dataset.dragging).toBe('false');
    const drop = fireEvent.drop(form, { dataTransfer });

    expect(onFiles).not.toHaveBeenCalled();
    // Prevented all the same, so the picture does not open in the tab.
    expect(drop).toBe(false);
});

test('an image-only paste is taken where asked; text wins over a picture', () => {
    const onFiles = vi.fn();
    const { getByTestId } = render(<Target onFiles={onFiles} paste />);

    expect(fireEvent.paste(getByTestId('child'), { clipboardData: withText('A1', [picture()]) })).toBe(true);
    expect(onFiles).not.toHaveBeenCalled();

    expect(fireEvent.paste(getByTestId('child'), { clipboardData: withFiles([picture()]) })).toBe(false);
    expect(onFiles).toHaveBeenCalledTimes(1);
});

test('without paste asked for, a pasted picture is left alone', () => {
    const onFiles = vi.fn();
    const { getByTestId } = render(<Target onFiles={onFiles} />);

    expect(fireEvent.paste(getByTestId('child'), { clipboardData: withFiles([picture()]) })).toBe(true);
    expect(onFiles).not.toHaveBeenCalled();
});

test('a picture dragged off a page shows no ring and is left to the shell', () => {
    const onFiles = vi.fn();
    const { getByTestId } = render(<Target onFiles={onFiles} />);
    const form = getByTestId('form');
    const dataTransfer = { types: ['text/uri-list', 'text/html', 'Files'], files: [picture()], getData: () => '' };

    const enter = fireEvent.dragEnter(form, { dataTransfer });
    expect(form.dataset.dragging).toBe('false');
    const drop = fireEvent.drop(form, { dataTransfer });

    expect(onFiles).not.toHaveBeenCalled();
    expect(enter).toBe(true);
    expect(drop).toBe(true);
});
