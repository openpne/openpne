import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { useState } from 'react';
import { afterEach, expect, test, vi } from 'vitest';
import { useShrunkPick } from './images-field';
import { SingleImageInput } from './single-image-input';
import { fakeT } from '@/lib/test-i18n';

vi.mock('@/lib/i18n', () => ({ useT: () => fakeT }));

const page = { imageUpload: { accept: 'image/jpeg,image/png', shrink: null, attachmentMaxBytes: 1024 } };
vi.mock('@inertiajs/react', () => ({ usePage: () => ({ props: page }) }));

afterEach(cleanup);

function Picker({ pictures }: { pictures: boolean }) {
    const [file, setFile] = useState<File | null>(null);
    const picked = useShrunkPick(file, setFile);

    return (
        <form>
            <SingleImageInput id="file" picked={picked} pictures={pictures} />
            <output data-testid="value">{file?.name ?? 'none'}</output>
        </form>
    );
}

test('with pictures off the input carries no accept list, takes a dropped document, and shows the cap note for one over the cap', () => {
    const { container } = render(<Picker pictures={false} />);
    const input = container.querySelector('input[type="file"]') as HTMLInputElement;
    expect(input.hasAttribute('accept')).toBe(false);

    fireEvent.drop(container.querySelector('form')!, { dataTransfer: { types: ['Files'], files: [new File([new Uint8Array(16)], 'minutes.pdf', { type: 'application/pdf' })], getData: () => '' } });
    expect(screen.getByTestId('value').textContent).toBe('minutes.pdf');

    fireEvent.change(input, { target: { files: [new File([new Uint8Array(2048)], 'deck.pdf', { type: 'application/pdf' })] } });
    expect(screen.getByTestId('value').textContent).toBe('none');
    expect(screen.getByRole('alert').textContent).toBe('A file can be up to 1 KB.');
});

test('with pictures on the input keeps the accept list and leaves a dropped document to the browser', () => {
    const { container } = render(<Picker pictures />);
    expect((container.querySelector('input[type="file"]') as HTMLInputElement).getAttribute('accept')).toBe('image/jpeg,image/png');

    fireEvent.drop(container.querySelector('form')!, { dataTransfer: { types: ['Files'], files: [new File([new Uint8Array(16)], 'minutes.pdf', { type: 'application/pdf' })], getData: () => '' } });
    expect(screen.getByTestId('value').textContent).toBe('none');
});
