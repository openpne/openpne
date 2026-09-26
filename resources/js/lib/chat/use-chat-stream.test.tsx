import { act, cleanup, renderHook } from '@testing-library/react';
import { afterEach, expect, test, vi } from 'vitest';
import { type ChatReactions, type ChatStreamEndpoints, SendFailed, useChatStream } from './use-chat-stream';
import type { ChatPage, ChatStreamRow } from './types';
import { answer, visibility, wire } from '@/lib/test-fetch';

vi.mock('@/lib/csrf', () => ({ xsrfHeader: () => ({ 'X-XSRF-TOKEN': 'token' }) }));

const restore = vi.hoisted(() => ({ consumed: false }));
vi.mock('@/lib/history-restore', () => ({ consumeHistoryRestore: () => restore.consumed }));

interface Row extends ChatStreamRow {
    body: string;
}

const row = (id: number, minute: number, body = `m${id}`): Row => {
    const createdAt = `2026-09-27T09:${String(minute).padStart(2, '0')}:00+00:00`;

    return { id, createdAt, cursor: `${createdAt}|${id}`, body };
};

const page = (messages: Row[], hasOlder = false, hasNewer = false): ChatPage<Row> => ({ messages, hasOlder, hasNewer });

const bodies = (result: { current: { messages: Row[] } }) => result.current.messages.map((message) => message.body);

const endpoints: ChatStreamEndpoints = { messages: (query) => `/talk${query}`, send: '/talk/send', delete: (id) => `/talk/${id}/delete` };

const reactions: ChatReactions = { initialVersion: 4, add: (id) => `/talk/${id}/react`, remove: (id) => `/talk/${id}/unreact` };

const POLL_MS = 8_000;

const tick = () =>
    act(() => {
        vi.advanceTimersByTime(POLL_MS);
    });

afterEach(() => {
    cleanup();
    vi.useRealTimers();
    vi.unstubAllGlobals();
    Reflect.deleteProperty(document, 'visibilityState');
    restore.consumed = false;
});

test('the poll asks after the newest cursor and folds what arrives', async () => {
    vi.useFakeTimers();
    const net = wire();
    const { result } = renderHook(() => useChatStream(endpoints, page([row(1, 0), row(2, 1)])));

    await tick();
    expect(net.url(0)).toBe(`/talk?after=${encodeURIComponent(row(2, 1).cursor)}`);
    expect(net.init(0)).toMatchObject({ credentials: 'same-origin', headers: { Accept: 'application/json' } });
    expect(net.init(0).signal).toBeInstanceOf(AbortSignal);

    await net.settle(0, answer(page([row(3, 2)])));
    expect(bodies(result)).toEqual(['m1', 'm2', 'm3']);
});

test('the reaction watermark rides the poll and moves with the answer', async () => {
    vi.useFakeTimers();
    const net = wire();
    const { result } = renderHook(() => useChatStream(endpoints, page([row(1, 0)]), reactions));

    await tick();
    expect(net.url(0)).toContain('&reactionsAfter=4');

    const touched = { ...row(1, 0), reactions: [{ emoji: '\u{1F44D}', count: 2, mine: false }] };
    await net.settle(0, answer({ ...page([]), touched: [touched], reactionsVersion: 6 }));
    expect(result.current.messages[0]?.reactions).toEqual(touched.reactions);

    await tick();
    expect(net.url(1)).toContain('&reactionsAfter=6');
});

test('an empty conversation asks for the newest page instead of a position', async () => {
    vi.useFakeTimers();
    const net = wire();
    const { result } = renderHook(() => useChatStream(endpoints, page([])));

    await tick();
    expect(net.url(0)).toBe('/talk');

    await net.settle(0, answer(page([row(1, 0)], true)));
    expect(bodies(result)).toEqual(['m1']);
    expect(result.current.hasOlder).toBe(true);
});

test('a hidden tab is not polled; coming back to it is', async () => {
    vi.useFakeTimers();
    const net = wire();
    visibility('hidden');
    renderHook(() => useChatStream(endpoints, page([row(1, 0)])));

    await tick();
    expect(net.fetch).not.toHaveBeenCalled();

    visibility('visible');
    act(() => {
        document.dispatchEvent(new Event('visibilitychange'));
    });
    expect(net.fetch).toHaveBeenCalledTimes(1);
});

test('the history window is not polled', async () => {
    vi.useFakeTimers();
    const net = wire();
    const { result } = renderHook(() => useChatStream(endpoints, page([row(1, 0)])));

    let opened!: Promise<boolean>;
    act(() => {
        opened = result.current.openContext(row(5, 4).cursor);
    });
    expect(net.url(0)).toBe(`/talk?context=${encodeURIComponent(row(5, 4).cursor)}`);
    await net.settle(0, answer(page([row(5, 4), row(6, 5)], true, true)));
    await expect(opened).resolves.toBe(true);
    expect(result.current.window).toEqual({ kind: 'history', hasNewer: true });
    expect(bodies(result)).toEqual(['m5', 'm6']);

    await tick();
    expect(net.fetch).toHaveBeenCalledTimes(1);
});

test('a page restored from history polls at mount', () => {
    vi.useFakeTimers();
    const net = wire();
    restore.consumed = true;
    renderHook(() => useChatStream(endpoints, page([row(1, 0)])));

    expect(net.fetch).toHaveBeenCalledTimes(1);
    expect(net.url(0)).toContain('?after=');
});

test('a poll answered after the window moved is dropped', async () => {
    vi.useFakeTimers();
    const net = wire();
    const { result } = renderHook(() => useChatStream(endpoints, page([row(1, 0)])));

    await tick();
    let opened!: Promise<boolean>;
    act(() => {
        opened = result.current.openContext(row(5, 4).cursor);
    });
    expect(net.init(0).signal?.aborted).toBe(true);
    await net.settle(1, answer(page([row(5, 4)], true, true)));
    await opened;

    // The stub ignores the abort, so what reaches the fold is a read the generation guard must drop.
    await net.settle(0, answer(page([row(2, 1)])));
    expect(bodies(result)).toEqual(['m5']);
});

test('a refused or dropped poll leaves the list as it was', async () => {
    vi.useFakeTimers();
    const net = wire();
    const { result } = renderHook(() => useChatStream(endpoints, page([row(1, 0)])));

    await tick();
    // A refusal that still carries a page-shaped body: what matters is that it is not read.
    await net.settle(0, answer(page([row(2, 1)]), 401));
    await tick();
    await net.drop(1);

    expect(bodies(result)).toEqual(['m1']);
});

test('unmounting stops the poll and lets the read out go', async () => {
    vi.useFakeTimers();
    const net = wire();
    const { unmount } = renderHook(() => useChatStream(endpoints, page([row(1, 0)])));

    await tick();
    unmount();
    expect(net.init(0).signal?.aborted).toBe(true);

    await tick();
    expect(net.fetch).toHaveBeenCalledTimes(1);
});

test('load older asks before the oldest cursor, reports while it is out, and is not asked again meanwhile', async () => {
    const net = wire();
    const { result } = renderHook(() => useChatStream(endpoints, page([row(2, 1), row(3, 2)], true)));

    let loading!: Promise<void>;
    act(() => {
        loading = result.current.loadOlder();
    });
    expect(net.url(0)).toBe(`/talk?before=${encodeURIComponent(row(2, 1).cursor)}`);
    expect(result.current.loadingOlder).toBe(true);

    act(() => {
        void result.current.loadOlder();
    });
    expect(net.fetch).toHaveBeenCalledTimes(1);

    await net.settle(0, answer(page([row(1, 0)], false)));
    await loading;
    expect(bodies(result)).toEqual(['m1', 'm2', 'm3']);
    expect(result.current.hasOlder).toBe(false);
    expect(result.current.loadingOlder).toBe(false);
});

test('load older with nothing on screen asks nothing', async () => {
    const net = wire();
    const { result } = renderHook(() => useChatStream(endpoints, page([])));

    await act(() => result.current.loadOlder());

    expect(net.fetch).not.toHaveBeenCalled();
});

test('a refused context read moves nothing and says so', async () => {
    const net = wire();
    const { result } = renderHook(() => useChatStream(endpoints, page([row(1, 0)])));

    let opened!: Promise<boolean>;
    act(() => {
        opened = result.current.openContext('gone');
    });
    await net.settle(0, answer(null, 404));

    await expect(opened).resolves.toBe(false);
    expect(result.current.window).toEqual({ kind: 'latest' });
    expect(bodies(result)).toEqual(['m1']);
});

test('of two moves out at once the later wins, whichever answers first', async () => {
    const net = wire();
    const { result } = renderHook(() => useChatStream(endpoints, page([row(1, 0)])));

    let first!: Promise<boolean>;
    let second!: Promise<boolean>;
    act(() => {
        first = result.current.openContext(row(5, 4).cursor);
    });
    act(() => {
        second = result.current.returnToLatest();
    });
    expect(net.url(1)).toBe('/talk');
    expect(net.init(0).signal?.aborted).toBe(true);

    await net.settle(0, answer(page([row(5, 4)], true, true)));
    await expect(first).resolves.toBe(false);
    await net.settle(1, answer(page([row(8, 7), row(9, 8)], true)));
    await expect(second).resolves.toBe(true);

    expect(result.current.window).toEqual({ kind: 'latest' });
    expect(bodies(result)).toEqual(['m8', 'm9']);
});

test('a return to latest overtaken by a context read is dropped, whichever answers first', async () => {
    const net = wire();
    const { result } = renderHook(() => useChatStream(endpoints, page([row(1, 0)])));

    let first!: Promise<boolean>;
    let second!: Promise<boolean>;
    act(() => {
        first = result.current.returnToLatest();
    });
    act(() => {
        second = result.current.openContext(row(5, 4).cursor);
    });

    await net.settle(0, answer(page([row(8, 7)], true)));
    await expect(first).resolves.toBe(false);
    await net.settle(1, answer(page([row(5, 4)], true, true)));
    await expect(second).resolves.toBe(true);

    expect(result.current.window).toEqual({ kind: 'history', hasNewer: true });
    expect(bodies(result)).toEqual(['m5']);
});

test('load newer asks after the newest cursor and lands in the latest window when nothing follows', async () => {
    const net = wire();
    const { result } = renderHook(() => useChatStream(endpoints, page([row(1, 0)])));

    let opened!: Promise<boolean>;
    act(() => {
        opened = result.current.openContext(row(5, 4).cursor);
    });
    await net.settle(0, answer(page([row(5, 4), row(6, 5)], true, true)));
    await opened;

    let loading!: Promise<void>;
    act(() => {
        loading = result.current.loadNewer();
    });
    expect(net.url(1)).toBe(`/talk?after=${encodeURIComponent(row(6, 5).cursor)}`);
    expect(result.current.loadingNewer).toBe(true);

    await net.settle(1, answer(page([row(7, 6)], true, false)));
    await loading;
    expect(result.current.window).toEqual({ kind: 'latest' });
    expect(bodies(result)).toEqual(['m5', 'm6', 'm7']);
    expect(result.current.loadingNewer).toBe(false);
});

test('send posts multipart with the images in pick order and folds the row answered', async () => {
    const net = wire();
    const { result } = renderHook(() => useChatStream(endpoints, page([row(1, 0)])));
    const first = new File(['a'], 'a.png', { type: 'image/png' });
    const second = new File(['b'], 'b.png', { type: 'image/png' });

    let sent!: Promise<void>;
    act(() => {
        sent = result.current.send('hi', [first, second], (form) => form.append('replyTo', '1'));
    });
    expect(net.url(0)).toBe('/talk/send');
    const init = net.init(0);
    expect(init.method).toBe('POST');
    expect(init.headers).toEqual({ 'X-XSRF-TOKEN': 'token', Accept: 'application/json' });
    const form = init.body as FormData;
    expect(form.get('body')).toBe('hi');
    expect(form.get('replyTo')).toBe('1');
    expect(form.getAll('images[]').map((image) => (image as File).name)).toEqual(['a.png', 'b.png']);

    await net.settle(0, answer(row(2, 1, 'hi')));
    await sent;
    expect(bodies(result)).toEqual(['m1', 'hi']);
});

test('a 422 surfaces the first message per field; any other refusal surfaces none', async () => {
    const net = wire();
    const { result } = renderHook(() => useChatStream(endpoints, page([])));

    let sent!: Promise<void>;
    act(() => {
        sent = result.current.send('x');
    });
    const invalid = expect(sent).rejects.toMatchObject({ errors: { body: 'Too long.', 'images.0': 'Not an image.' } });
    await net.settle(0, answer({ errors: { body: ['Too long.', 'And more.'], 'images.0': ['Not an image.'] } }, 422));
    await invalid;

    act(() => {
        sent = result.current.send('x');
    });
    const refused = expect(sent).rejects.toEqual(new SendFailed({}));
    await net.settle(1, answer(null, 500));
    await refused;
    expect(result.current.messages).toEqual([]);
});

test('a message sent from history is folded under the newest page', async () => {
    const net = wire();
    const { result } = renderHook(() => useChatStream(endpoints, page([row(1, 0)])));

    let opened!: Promise<boolean>;
    act(() => {
        opened = result.current.openContext(row(5, 4).cursor);
    });
    await net.settle(0, answer(page([row(5, 4)], true, true)));
    await opened;

    let sent!: Promise<void>;
    act(() => {
        sent = result.current.send('hi');
    });
    await net.settle(1, answer(row(9, 8, 'hi')));
    expect(net.url(2)).toBe('/talk');
    await net.settle(2, answer(page([row(8, 7)], true)));
    await sent;

    expect(result.current.window).toEqual({ kind: 'latest' });
    expect(bodies(result)).toEqual(['m8', 'hi']);
});

test('when the newest page cannot be re-read after a send from history, the list is your message alone', async () => {
    const net = wire();
    const { result } = renderHook(() => useChatStream(endpoints, page([row(1, 0)])));

    let opened!: Promise<boolean>;
    act(() => {
        opened = result.current.openContext(row(5, 4).cursor);
    });
    await net.settle(0, answer(page([row(5, 4)], true, true)));
    await opened;

    let sent!: Promise<void>;
    act(() => {
        sent = result.current.send('hi');
    });
    await net.settle(1, answer(row(9, 8, 'hi')));
    await net.settle(2, answer(null, 500));
    await sent;

    expect(result.current.window).toEqual({ kind: 'latest' });
    expect(bodies(result)).toEqual(['hi']);
    expect(result.current.hasOlder).toBe(true);
});

test('send, remove and react refuse a conversation that declared no endpoint for them', async () => {
    const net = wire();
    const { result } = renderHook(() => useChatStream({ messages: (query) => `/talk${query}` }, page([row(1, 0)])));

    await expect(result.current.send('x')).rejects.toThrow('read-only');
    await expect(result.current.remove(1)).rejects.toThrow('read-only');
    await expect(result.current.react(1, '\u{1F44D}', 'add')).rejects.toThrow('no reactions');
    expect(net.fetch).not.toHaveBeenCalled();
});

test('remove posts to the delete route and drops the row; a refusal leaves it', async () => {
    const net = wire();
    const { result } = renderHook(() => useChatStream(endpoints, page([row(1, 0), row(2, 1)])));

    let removed!: Promise<void>;
    act(() => {
        removed = result.current.remove(2);
    });
    expect(net.url(0)).toBe('/talk/2/delete');
    expect(net.init(0)).toMatchObject({ method: 'POST', credentials: 'same-origin', headers: { 'X-XSRF-TOKEN': 'token' } });
    await net.settle(0, answer(null, 403));
    await removed;
    expect(bodies(result)).toEqual(['m1', 'm2']);

    act(() => {
        removed = result.current.remove(2);
    });
    await net.settle(1, answer(null, 200));
    await removed;
    expect(bodies(result)).toEqual(['m1']);
});

test('react posts the emoji to the add or remove route and draws the outcome; a refusal returns false', async () => {
    const net = wire();
    const { result } = renderHook(() => useChatStream(endpoints, page([row(1, 0)]), reactions));

    let reacted!: Promise<boolean>;
    act(() => {
        reacted = result.current.react(1, '\u{1F44D}', 'add');
    });
    expect(net.url(0)).toBe('/talk/1/react');
    expect(net.init(0)).toMatchObject({ method: 'POST', body: JSON.stringify({ emoji: '\u{1F44D}' }), headers: { 'Content-Type': 'application/json' } });
    await net.settle(0, answer({}, 200));
    await expect(reacted).resolves.toBe(true);
    expect(result.current.messages[0]?.reactions).toEqual([{ emoji: '\u{1F44D}', count: 1, mine: true }]);

    act(() => {
        reacted = result.current.react(1, '\u{1F44D}', 'remove');
    });
    expect(net.url(1)).toBe('/talk/1/unreact');
    await net.settle(1, answer({}, 422));
    await expect(reacted).resolves.toBe(false);
    expect(result.current.messages[0]?.reactions).toEqual([{ emoji: '\u{1F44D}', count: 1, mine: true }]);
});

test('a reaction answered after the poll moved the watermark is left to the poll', async () => {
    vi.useFakeTimers();
    const net = wire();
    const { result } = renderHook(() => useChatStream(endpoints, page([row(1, 0)]), reactions));

    let reacted!: Promise<boolean>;
    act(() => {
        reacted = result.current.react(1, '\u{1F44D}', 'add');
    });
    await tick();
    await net.settle(1, answer({ ...page([]), touched: [], reactionsVersion: 5 }));
    await net.settle(0, answer({}, 200));

    await expect(reacted).resolves.toBe(true);
    expect(result.current.messages[0]?.reactions).toBeUndefined();
});
