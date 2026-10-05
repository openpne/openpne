import { cleanup, fireEvent, screen, waitFor } from '@testing-library/react';
import { afterEach, expect, test, vi } from 'vitest';
import { ConversationComposer } from './composer';
import { fakeT } from '@/lib/test-i18n';
import { renderWithProviders } from '@/lib/test-render';

// useT reads the Inertia page for its term map, which a component test has no page to give it.
vi.mock('@/lib/i18n', () => ({ useT: () => fakeT }));

// The composer reads the upload policy from the page's shared props.
vi.mock('@inertiajs/react', () => ({ usePage: () => ({ props: { imageUpload: { accept: 'image/jpeg,image/png,image/gif,image/webp', shrink: null } } }) }));

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

// Plain shapes: the test renderer copies an init's own properties onto a fresh DataTransfer, and a real one keeps its files behind getters.
const picture = (name: string) => new File([new Uint8Array(4)], name, { type: 'image/png' });
const filesDrag = (files: File[]) => ({ types: ['Files'], files, getData: () => '' });

function mount(onSend: (body: string, images: File[]) => Promise<void> = vi.fn(async () => {})) {
    vi.stubGlobal('URL', Object.assign(URL, { createObjectURL: () => 'blob:preview', revokeObjectURL: () => {} }));
    renderWithProviders(<ConversationComposer counterpartName="Rin" onSend={onSend} />);

    return screen.getByLabelText('Message').closest('form') as HTMLFormElement;
}

test('a picture dropped on the bar or pasted into it is attached like a pick, and text pastes as text', () => {
    const form = mount();

    fireEvent.drop(form, { dataTransfer: filesDrag([picture('dropped.png')]) });
    expect(screen.getAllByLabelText(/Remove image/)).toHaveLength(1);

    fireEvent.paste(screen.getByLabelText('Message'), { clipboardData: { files: [picture('shot.png')], getData: () => '' } });
    expect(screen.getAllByLabelText(/Remove image/)).toHaveLength(2);

    expect(fireEvent.paste(screen.getByLabelText('Message'), { clipboardData: { files: [picture('cell.png')], getData: (type: string) => (type === 'text/plain' ? 'A1' : '') } })).toBe(true);
    expect(screen.getAllByLabelText(/Remove image/)).toHaveLength(2);
});

test('a picture dropped while a send is in flight is ignored, as the attach button is', async () => {
    let finish!: () => void;
    const onSend = vi.fn(() => new Promise<void>((resolve) => { finish = resolve; }));
    const form = mount(onSend);
    const field = screen.getByLabelText('Message');

    fireEvent.change(field, { target: { value: 'on my way' } });
    fireEvent.submit(form);
    expect(onSend).toHaveBeenCalledOnce();

    fireEvent.drop(form, { dataTransfer: filesDrag([picture('late.png')]) });
    fireEvent.paste(field, { clipboardData: { files: [picture('late2.png')], getData: () => '' } });
    expect(screen.queryAllByLabelText(/Remove image/)).toHaveLength(0);

    finish();
    await waitFor(() => expect(onSend.mock.results).toHaveLength(1));
    expect(screen.queryAllByLabelText(/Remove image/)).toHaveLength(0);
});

test('at the cap a drop on the bar is swallowed without a note, as the disabled button takes no pick', () => {
    const form = mount();

    fireEvent.drop(form, { dataTransfer: filesDrag([picture('a.png'), picture('b.png'), picture('c.png')]) });
    expect(screen.getAllByLabelText(/Remove image/)).toHaveLength(3);

    fireEvent.drop(form, { dataTransfer: filesDrag([picture('d.png')]) });
    expect(screen.getAllByLabelText(/Remove image/)).toHaveLength(3);
    expect(screen.queryByText(/You can attach up to/)).toBeNull();
});
