import { cleanup, fireEvent, screen, waitFor } from '@testing-library/react';
import { afterEach, expect, test, vi } from 'vitest';
import { TalkComposer } from './composer';
import { fakeT } from '@/lib/test-i18n';
import { renderWithProviders } from '@/lib/test-render';
import type { GridImage } from '@/components/image-grid';
import type { TalkMessage } from './types';

// useT reads the Inertia page for its term map, which a component test has no page to give it.
vi.mock('@/lib/i18n', () => ({ useT: () => fakeT }));

// The composer reads the upload accept list from the page's shared props.
vi.mock('@inertiajs/react', () => ({ usePage: () => ({ props: { imageUpload: { accept: 'image/jpeg,image/png,image/gif,image/webp', shrink: null } } }) }));

afterEach(cleanup);

const image: GridImage = {
    id: 1,
    url: 'https://sns.test/i.jpg',
    thumbnailUrl: 'https://sns.test/t.jpg',
    fitSources: [],
    cropSources: {},
    width: null,
    height: null,
    animatedSources: [],
};

const parent = (over: Partial<TalkMessage> = {}): TalkMessage => ({
    id: 7,
    cursor: '7',
    body: 'Bring the good rope',
    author: { id: 3, name: 'Rin', imageUrl: null, avatarColor: null, isAi: false },
    mentions: [],
    images: [],
    linkCard: null,
    reactions: [],
    inReplyTo: null,
    createdAt: '2026-08-16T10:00:00+09:00',
    isOwn: false,
    canDelete: false,
    ...over,
});

function mount(over: Partial<Parameters<typeof TalkComposer>[0]> = {}) {
    const props = { groupId: 1, groupName: 'Rope crew', replyTo: null, onCancelReply: vi.fn(), onSend: vi.fn(), ...over };
    renderWithProviders(<TalkComposer {...props} />);

    return props;
}

test('with nothing staged the composer shows no reply strip', () => {
    mount();

    expect(screen.queryByRole('button', { name: 'Cancel reply' })).toBeNull();
});

test('a staged reply names who and what is answered, and can be taken back', () => {
    const { onCancelReply } = mount({ replyTo: parent() });

    expect(screen.getByText('Replying to Rin')).toBeTruthy();
    expect(screen.getByText('Bring the good rope')).toBeTruthy();

    fireEvent.click(screen.getByRole('button', { name: 'Cancel reply' }));
    expect(onCancelReply).toHaveBeenCalled();
});

test('a staged reply to a picture-only message previews as one', () => {
    mount({ replyTo: parent({ body: '   ', images: [image] }) });

    expect(screen.getByText('Image')).toBeTruthy();
});

test('a staged reply to a withdrawn author names them with the established label', () => {
    mount({ replyTo: parent({ author: null }) });

    expect(screen.getByText('Replying to Withdrawn member')).toBeTruthy();
});

// Plain shapes: the test renderer copies an init's own properties onto a fresh DataTransfer, and a real one keeps its files behind getters.
const picture = (name: string) => new File([new Uint8Array(4)], name, { type: 'image/png' });
const filesDrag = (files: File[]) => ({ types: ['Files'], files, getData: () => '' });

function withObjectUrls() {
    vi.stubGlobal('URL', Object.assign(URL, { createObjectURL: () => 'blob:preview', revokeObjectURL: () => {} }));
}

test('a picture dropped on the bar or pasted into it is attached like a pick, and text pastes as text', () => {
    withObjectUrls();
    mount();
    const form = screen.getByLabelText('Message').closest('form') as HTMLFormElement;

    fireEvent.drop(form, { dataTransfer: filesDrag([picture('dropped.png')]) });
    expect(screen.getAllByLabelText(/Remove image/)).toHaveLength(1);

    fireEvent.paste(screen.getByLabelText('Message'), { clipboardData: { files: [picture('shot.png')], getData: () => '' } });
    expect(screen.getAllByLabelText(/Remove image/)).toHaveLength(2);

    // A copy from a spreadsheet carries a picture and its text: the text is what was meant.
    const both = fireEvent.paste(screen.getByLabelText('Message'), { clipboardData: { files: [picture('cell.png')], getData: (type: string) => (type === 'text/plain' ? 'A1' : '') } });
    expect(both).toBe(true);
    expect(screen.getAllByLabelText(/Remove image/)).toHaveLength(2);
});

test('a picture dropped while a send is in flight is ignored, as the attach button is', async () => {
    withObjectUrls();
    let finish!: () => void;
    const onSend = vi.fn(() => new Promise<void>((resolve) => { finish = resolve; }));
    mount({ onSend });
    const field = screen.getByLabelText('Message');
    const form = field.closest('form') as HTMLFormElement;

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
    withObjectUrls();
    mount();
    const form = screen.getByLabelText('Message').closest('form') as HTMLFormElement;

    fireEvent.drop(form, { dataTransfer: filesDrag([picture('a.png'), picture('b.png'), picture('c.png')]) });
    expect(screen.getAllByLabelText(/Remove image/)).toHaveLength(3);

    fireEvent.drop(form, { dataTransfer: filesDrag([picture('d.png')]) });
    expect(screen.getAllByLabelText(/Remove image/)).toHaveLength(3);
    expect(screen.queryByText(/You can attach up to/)).toBeNull();
});
