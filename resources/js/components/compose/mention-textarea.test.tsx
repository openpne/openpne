import { act, cleanup, fireEvent, render, screen } from '@testing-library/react';
import { useState } from 'react';
import { afterEach, beforeEach, expect, test, vi } from 'vitest';
import { MentionTextarea } from './mention-textarea';
import { fakeT } from '@/lib/test-i18n';
import { answer, wire } from '@/lib/test-fetch';
import { MAX_MENTIONS, type DraftMention } from '@/lib/mention-draft';

vi.mock('@/lib/i18n', () => ({ useT: () => fakeT }));
vi.mock('@/components/avatar', () => ({ Avatar: () => null }));

const DEBOUNCE_MS = 200;

const aoi = { id: 7, name: 'Aoi', imageUrl: null, avatarColor: null, isAi: false };
const aoba = { id: 8, name: 'Aoba', imageUrl: null, avatarColor: null, isAi: false };
const aoto = { id: 9, name: 'Aoto', imageUrl: null, avatarColor: null, isAi: false };

const seen = { value: '', mentions: [] as DraftMention[] };

function Harness({ mentions = [] as DraftMention[], candidatesUrl }: { mentions?: DraftMention[]; candidatesUrl?: string }) {
    const [value, setValue] = useState('');
    const [draft, setDraft] = useState(mentions);
    seen.value = value;
    seen.mentions = draft;

    return <MentionTextarea aria-label="Body" value={value} onChange={setValue} mentions={draft} onMentionsChange={setDraft} candidatesUrl={candidatesUrl} />;
}

const field = () => screen.getByRole('textbox', { name: 'Body' }) as HTMLTextAreaElement;
const options = () => screen.queryAllByRole('option').map((option) => option.textContent);
const optionId = (index: number) => screen.getAllByRole('option')[index]?.id;

function type(value: string, caret = value.length) {
    fireEvent.change(field(), { target: { value, selectionStart: caret, selectionEnd: caret } });
}

const advance = (ms: number) =>
    act(() => {
        vi.advanceTimersByTime(ms);
    });

beforeEach(() => {
    vi.useFakeTimers();
});

afterEach(() => {
    cleanup();
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

test('a search waits for the typing to pause and asks the endpoint it was handed', async () => {
    const net = wire();
    render(<Harness candidatesUrl="/groups/2/talk/mention-candidates?scope=room" />);

    type('@a');
    advance(DEBOUNCE_MS - 1);
    type('@ao');
    advance(DEBOUNCE_MS - 1);
    expect(net.fetch).not.toHaveBeenCalled();

    advance(1);
    expect(net.fetch).toHaveBeenCalledTimes(1);
    expect(net.url(0)).toBe('/groups/2/talk/mention-candidates?scope=room&q=ao');

    await net.settle(0, answer({ candidates: [aoi, aoba] }));
    expect(options()).toEqual(['Aoi', 'Aoba']);
    expect(field().getAttribute('aria-activedescendant')).toBe(optionId(0));
});

test('an answer to a query the field has typed past is dropped', async () => {
    const net = wire();
    render(<Harness />);

    type('@a');
    advance(DEBOUNCE_MS);
    type('@ao');
    expect((net.init(0).signal as AbortSignal).aborted).toBe(true);

    await net.settle(0, answer({ candidates: [aoi] }));
    expect(options()).toEqual([]);

    advance(DEBOUNCE_MS);
    await net.settle(1, answer({ candidates: [aoba] }));
    expect(options()).toEqual(['Aoba']);
});

test('the arrows walk the list and Enter picks the active one, caret past the handle', async () => {
    const net = wire();
    render(<Harness />);
    type('hi @ao there', 'hi @ao'.length);
    advance(DEBOUNCE_MS);
    await net.settle(0, answer({ candidates: [aoi, aoba, aoto] }));

    fireEvent.keyDown(field(), { key: 'ArrowUp' });
    expect(field().getAttribute('aria-activedescendant')).toBe(optionId(2));
    fireEvent.keyDown(field(), { key: 'ArrowDown' });
    fireEvent.keyDown(field(), { key: 'ArrowDown' });
    fireEvent.keyDown(field(), { key: 'Enter' });

    expect(seen.value).toBe('hi @Aoba  there');
    expect(seen.mentions).toEqual([{ memberId: 8, label: 'Aoba', start: 3 }]);
    expect([field().selectionStart, field().selectionEnd]).toEqual(['hi @Aoba '.length, 'hi @Aoba '.length]);
    expect(options()).toEqual([]);
});

test('a click picks without the field losing the list to a blur first', async () => {
    const net = wire();
    render(<Harness />);
    type('@ao');
    advance(DEBOUNCE_MS);
    await net.settle(0, answer({ candidates: [aoi] }));

    const option = screen.getByRole('option');
    expect(fireEvent.mouseDown(option)).toBe(false);
    fireEvent.click(option);

    expect(seen.value).toBe('@Aoi ');
});

test('Escape gives up on this trigger alone', async () => {
    const net = wire();
    render(<Harness />);
    type('@ao');
    advance(DEBOUNCE_MS);
    await net.settle(0, answer({ candidates: [aoi] }));

    fireEvent.keyDown(field(), { key: 'Escape' });
    expect(options()).toEqual([]);
    type('@aoi');
    advance(DEBOUNCE_MS);
    expect(net.fetch).toHaveBeenCalledTimes(1);

    type('');
    type('@ao');
    advance(DEBOUNCE_MS);
    expect(net.fetch).toHaveBeenCalledTimes(2);
});

test.each([
    ['refused', (net: ReturnType<typeof wire>) => net.settle(0, answer({ candidates: [aoi] }, 429))],
    ['failed', (net: ReturnType<typeof wire>) => net.drop(0)],
])('a %s search closes the list and leaves the keys to the field', async (_, fail) => {
    const net = wire();
    render(<Harness />);
    type('@ao');
    advance(DEBOUNCE_MS);
    await fail(net);

    expect(options()).toEqual([]);
    expect(fireEvent.keyDown(field(), { key: 'Enter' })).toBe(true);
    expect(seen.value).toBe('@ao');
});

test('a failed search drops the answer held for an earlier query', async () => {
    const net = wire();
    render(<Harness />);
    type('@a');
    advance(DEBOUNCE_MS);
    await net.settle(0, answer({ candidates: [aoi] }));
    type('@ao');
    advance(DEBOUNCE_MS);
    await net.drop(1);

    type('@a');

    expect(options()).toEqual([]);
});

test('a converting IME searches nothing until it commits', () => {
    const net = wire();
    render(<Harness />);

    fireEvent.compositionStart(field());
    type('@あお');
    advance(DEBOUNCE_MS);
    expect(net.fetch).not.toHaveBeenCalled();

    fireEvent.compositionEnd(field());
    advance(DEBOUNCE_MS);
    expect(net.url(0)).toBe(`/timeline/mention-candidates?q=${encodeURIComponent('あお')}`);
});

test.each([
    [MAX_MENTIONS - 1, 1],
    [MAX_MENTIONS, 0],
])('a draft of %i mentions is searched for %i times', (held, searches) => {
    const net = wire();
    const draft = Array.from({ length: held }, (_, i) => ({ memberId: i + 1, label: 'x', start: 100 + i * 3 }));
    render(<Harness mentions={draft} />);

    type('@ao');
    advance(DEBOUNCE_MS);

    expect(net.fetch).toHaveBeenCalledTimes(searches);
});

test('a key pressed while an IME converts over an open list is the field\'s', async () => {
    const net = wire();
    render(<Harness />);
    type('@ao');
    advance(DEBOUNCE_MS);
    await net.settle(0, answer({ candidates: [aoi] }));

    fireEvent.compositionStart(field());

    expect(fireEvent.keyDown(field(), { key: 'Enter' })).toBe(true);
    expect(seen.value).toBe('@ao');
});

test('leaving the field closes the list', async () => {
    const net = wire();
    render(<Harness />);
    type('@ao');
    advance(DEBOUNCE_MS);
    await net.settle(0, answer({ candidates: [aoi] }));

    fireEvent.blur(field());

    expect(options()).toEqual([]);
});
