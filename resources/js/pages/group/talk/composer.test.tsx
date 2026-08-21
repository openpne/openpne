import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, expect, test, vi } from 'vitest';
import { TalkComposer } from './composer';
import { fakeT } from '@/lib/test-i18n';
import type { GridImage } from '@/components/image-grid';
import type { TalkMessage } from './types';

// useT reads the Inertia page for its term map, which a component test has no page to give it.
vi.mock('@/lib/i18n', () => ({ useT: () => fakeT }));

afterEach(cleanup);

const image: GridImage = {
    id: 1,
    url: 'https://sns.test/i.jpg',
    thumbnailUrl: 'https://sns.test/t.jpg',
    fitSources: [],
    cropSources: {},
    width: null,
    height: null,
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
    render(<TalkComposer {...props} />);

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
