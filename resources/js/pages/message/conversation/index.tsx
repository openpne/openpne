import { Head, usePage } from '@inertiajs/react';
import { ArrowDown, ArrowUp } from 'lucide-react';
import { Fragment, useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { ChatDayHeading } from '@/components/chat-day-heading';
import { ChatScrollDay } from '@/components/chat-scroll-day';
import { Button } from '@/components/ui/button';
import { Panel } from '@/components/ui/surface';
import { arrivalsAfter, type ChatSeenMark } from '@/lib/chat/arrivals';
import { useChatStream } from '@/lib/chat/use-chat-stream';
import { useScrollDay } from '@/lib/chat/use-scroll-day';
import { useMarkRead } from '@/lib/chat/use-mark-read';
import { separatorsAbove } from '@/lib/chat/separators';
import { dividerBeforeId, firstUnreadBoundary } from '@/lib/chat/unread';
import { useT } from '@/lib/i18n';
import { useDateFormat } from '@/lib/use-date-format';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';
import type { MessageMember } from '../types';
import { ConversationComposer } from './composer';
import { DeleteConversation } from './delete-conversation';
import { ConversationMessageRow } from './message-row';
import type { ConversationPage, ConversationUnreadSnapshot } from './types';

interface ConversationProps extends PageProps {
    /** Who the conversation is with; null is the bucket every withdrawn member's messages fall into. */
    counterpart: MessageMember | null;
    page: ConversationPage;
    /** The message a `?m=` link opened on; null for an ordinary visit. `page` is the slice it sits in. */
    anchor: number | null;
    unreadSnapshot: ConversationUnreadSnapshot | null;
    /** Whether this conversation has a composer at all: false for the withdrawn bucket and a refused pair. */
    canSend: boolean;
}

/** How close to the foot still counts as reading the newest message. */
const NEAR_BOTTOM_PX = 96;

/** How long the message a deep link landed on stays picked out. */
const HIGHLIGHT_MS = 2_000;

/** One conversation list per page, so the message's own id names its row without a container ref. */
const messageElement = (id: number): Element | null => document.querySelector(`[data-conversation-message-id="${id}"]`);

/** Whether the reader is standing at the foot of what is loaded. */
const atFoot = (): boolean => window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - NEAR_BOTTOM_PX;

export default function MessageConversation() {
    const t = useT();
    const date = useDateFormat();
    const { counterpart, page, anchor, unreadSnapshot, canSend } = usePage<ConversationProps>().props;
    // A departed member leaves no id to key a conversation by, so every one of them is addressed by
    // the bucket's own literal.
    const path = `/messages/${counterpart?.id ?? 'withdrawn'}`;
    // Memoized because the stream's poll and reads hang off their identity: rebuilt every render, the
    // interval would be torn down and started again each time the page re-rendered.
    const endpoints = useMemo(
        // The conversation is read-only wherever it cannot be written to — the withdrawn bucket, a
        // blocked or banned pair — and the stream is handed no send endpoint at all there.
        () => ({ messages: (query: string) => `${path}/messages${query}`, send: canSend ? path : undefined }),
        [path, canSend],
    );
    const stream = useChatStream(endpoints, page);
    const messages = stream.messages;
    const streamSend = stream.send;
    const atLatest = stream.window.kind === 'latest';
    const generation = stream.generation;

    // Whether the reader is standing at the newest message, which is what the "jump to latest" pill
    // answers to — and, with the live window, what counts as having read what arrived.
    const [atBottom, setAtBottom] = useState(true);
    useMarkRead(`${path}/read`, messages[messages.length - 1]?.id, atBottom && atLatest);
    const [seen, setSeen] = useState<ChatSeenMark | null>(null);


    // Where the reader has actually stood — see the talk page, whose rule this follows.
    useEffect(() => {
        const newest = messages[messages.length - 1];
        if (!atBottom || !atLatest || newest === undefined) {
            return;
        }

        setSeen((current) =>
            current !== null && current.id === newest.id && current.at === newest.createdAt
                ? current
                : { at: newest.createdAt, id: newest.id },
        );
    }, [atBottom, atLatest, messages]);

    // The line the visit opened on. Both this and the banner below come off the render-time snapshot
    // and nothing else — mark-read never writes props, which is what makes them survive the one that
    // fires seconds later. The snapshot's cursor is the boundary as a position, so the jump still
    // lands on it after every receipt behind it has been opened. The one writer that does reach the
    // snapshot is the reload after a history restore (lib/revalidate-on-restore.ts), deliberately: a
    // restore is a fresh arrival, and the line belongs where a fresh visit would draw it.
    const dividerId = dividerBeforeId(messages, firstUnreadBoundary(unreadSnapshot), stream.hasOlder);
    // The backlog the page cannot draw a line for, because the boundary is further back than it has
    // loaded. Null when the line is on screen, or when there was nothing waiting to begin with.
    const backlog = dividerId === null && unreadSnapshot !== null ? unreadSnapshot : null;
    const arrivals = arrivalsAfter(messages, seen);
    // Where the reading area begins, handed to the hook so the offset stays in the class that sets it.
    const scrollDayLine = useRef<HTMLDivElement>(null);
    const scrollDay = useScrollDay('[data-conversation-message-id]', scrollDayLine, backlog !== null, () => atFoot() && atLatest);
    const scrollDayAt = scrollDay.index === null ? null : (messages[scrollDay.index]?.createdAt ?? null);

    // What the pill says. English needs the singular said differently, and this is the place in the
    // app where a count of one is the *common* case — a conversation usually gets one message at a
    // time. `useT` exposes only the string form (lib/i18n), so the choice belongs to the caller; the
    // shape is the one use-date-format already uses for "a minute ago".
    const latestLabel =
        arrivals === 0 ? t('Jump to latest') : arrivals === 1 ? t('1 new message') : t(':count new messages', { count: arrivals });

    // The message this visit opened on, and its emphasis. The landing is a ref because it describes
    // the arrival rather than the render — the scroll it drives happens once, on mount — while the
    // highlight is state because it expires.
    const landing = useRef(anchor);
    const [highlightId, setHighlightId] = useState(landing.current);

    // A conversation that scrolls itself while someone is reading back through it has taken the page
    // away from them, so new arrivals only follow the reader who is already at the foot.
    const pinned = useRef(true);
    // The message "load older" was standing on, and where it sat in the viewport.
    const heldRow = useRef<{ id: number; top: number } | null>(null);
    // A move the reader asked for, and the list it was asked from. It is spent on the render that
    // carries its own answer — the generation moves only when a window change lands, so a poll or a
    // merge arriving in between cannot spend it and leave the jump un-made.
    const goTo = useRef<{ target: 'divider' | 'bottom'; from: number } | null>(null);
    // The newest message the pin last answered. The pin follows new content, not new array identity:
    // merges rebuild the list for reasons that move nothing (a re-read row).
    const tail = useRef<number | undefined>(undefined);

    useEffect(() => {
        const onScroll = () => {
            pinned.current = atFoot();
            // Mirrored into state because the pill has to re-render when it changes; the ref stays
            // because the scroll-pinning layout effect needs the value without a re-render.
            setAtBottom(pinned.current);
        };
        window.addEventListener('scroll', onScroll, { passive: true });

        return () => window.removeEventListener('scroll', onScroll);
    }, []);

    // Open on the newest message, the way a conversation is read — or on the message a link named.
    useLayoutEffect(() => {
        const element = landing.current === null ? null : messageElement(landing.current);
        if (element === null) {
            window.scrollTo({ top: document.documentElement.scrollHeight });

            return;
        }

        // Mid-viewport, so what was said around the message lands with it; a row at the top edge
        // reads as the start of the conversation.
        element.scrollIntoView({ block: 'center' });
        // The scroll listener has not run to say where this left the reader, and the pin effect below
        // is about to ask: a landing behind the newest message must not be answered as the foot.
        pinned.current = atFoot();
        setAtBottom(pinned.current);
    }, []);

    // The emphasis is temporary; the landing is not. Nothing re-arms it, so a reader who scrolls away
    // and back finds the message where it was rather than flashing again.
    useEffect(() => {
        if (highlightId === null) {
            return;
        }

        const timer = setTimeout(() => setHighlightId(null), HIGHLIGHT_MS);

        return () => clearTimeout(timer);
    }, [highlightId]);

    useLayoutEffect(() => {
        const newest = messages[messages.length - 1]?.id;
        const arrived = newest !== tail.current;
        tail.current = newest;

        const asked = goTo.current;
        if (asked !== null && asked.from !== generation) {
            goTo.current = null;
            // A jump outranks both rules below: the reader named the place, so neither the held
            // anchor nor the pin gets to answer for this render.
            if (asked.target === 'bottom') {
                window.scrollTo({ top: document.documentElement.scrollHeight });
            } else {
                // Mid-viewport, so the last of what was already read stays visible above the line —
                // landing on the boundary with nothing above it reads as the start of the thread.
                document.querySelector('[data-conversation-divider]')?.scrollIntoView({ block: 'center' });
            }

            return;
        }

        const held = heldRow.current;
        if (held !== null) {
            heldRow.current = null;
            // History was prepended: put the message the reader was on back where it was, so the page
            // grows upward instead of jumping.
            const element = messageElement(held.id);
            if (element !== null) {
                window.scrollBy({ top: element.getBoundingClientRect().top - held.top });

                return;
            }
        }

        // Only the live window follows new arrivals: "load newer" appends a page the reader is about
        // to read, and scrolling past it would be the page taking their place away.
        if (arrived && pinned.current && atLatest) {
            window.scrollTo({ top: document.documentElement.scrollHeight });
        }
    }, [messages, atLatest, generation]);

    const loadOlder = () => {
        const first = messages[0];
        const element = first === undefined ? null : messageElement(first.id);
        heldRow.current = first !== undefined && element !== null ? { id: first.id, top: element.getBoundingClientRect().top } : null;

        void stream.loadOlder();
    };

    // The move is claimed before the read, since the state it lands on may be committed before this
    // handler resumes; a read that brings nothing back gives it up again rather than leaving a jump
    // armed for whatever changes the list next.
    const jumpToContext = (cursor: string) => {
        const asked = { target: 'divider' as const, from: generation };
        goTo.current = asked;
        void stream.openContext(cursor).then((moved) => {
            // Only give up the move if it is still the one this click made.
            if (!moved && goTo.current === asked) {
                goTo.current = null;
            }
        });
    };

    // From the live window this is a scroll; from a history window the newest messages are not even
    // loaded, so it is a read first and the scroll lands on what comes back.
    const jumpToLatest = () => {
        pinned.current = true;

        if (atLatest) {
            window.scrollTo({ top: document.documentElement.scrollHeight });

            return;
        }

        const asked = { target: 'bottom' as const, from: generation };
        goTo.current = asked;
        void stream.returnToLatest().then((moved) => {
            // Only give up the move if it is still the one this click made.
            if (!moved && goTo.current === asked) {
                goTo.current = null;
            }
        });
    };

    // Your own send always lands you on your own words. The pinned gate protects someone reading
    // back through history from being yanked by the *counterpart's* arrivals; writing is the opposite
    // intent — and it is claimed before the write, because what the write commits may be rendered
    // before this handler is resumed to say so.
    const send = async (body: string, images: File[]) => {
        pinned.current = true;
        await streamSend(body, images);
        setAtBottom(true);
    };

    // Built once so the list's bottom rhythm and the pill's offset answer to the same fact: whether
    // the page ends on a bar or on its own last row.
    const composer = counterpart !== null && canSend ? <ConversationComposer counterpartName={counterpart.name} onSend={send} /> : null;

    return (
        <>
            <Head title={counterpart?.name ?? t('Withdrawn member')} />

            {/* The withdrawn bucket keeps it: nobody can write there again, so it is the one
                conversation whose only remaining action is clearing it out. */}
            <DeleteConversation path={path} />

            {/* Withheld while the banner has this slot — one slot, one occupant (chat-scroll-day.tsx).
                That ties this to how the banner's life is decided, so moving one moves the other.

                And withheld at the foot of the live window, which is where every scroll this page
                makes for itself happens: it opens there, it follows arrivals there, and it goes there
                on a send. Those all raise a scroll event the indicator cannot tell from a reader's, so
                without this a message arriving under a settled reader put a date over their words —
                once per message, in the state this exists to keep clear. The gate reads as sense
                besides: at the foot there is no question of which day, and the last heading is usually
                still on screen. */}
<ChatScrollDay ref={scrollDayLine} at={scrollDayAt} />

            {backlog !== null && (
                // Sticky, because the reader opens at the foot of the conversation and the boundary
                // this offers is a page or more above them — a band at the top of the list would be
                // out of sight exactly when it is needed.
                <div className="sticky top-[calc(var(--modern-top-offset)+0.5rem)] z-20 flex justify-center">
                    <Button size="sm" variant="secondary" onClick={() => jumpToContext(backlog.cursor)} className="shadow-md">
                        <ArrowUp className="size-4" aria-hidden />
                        {t('Jump to :count unread messages', { count: backlog.count })}
                    </Button>
                </div>
            )}

            {/* With no composer standing on the page's foot, the list takes back both that rhythm and
                the home-indicator strip the shell leaves the composer, rather than ending the page on
                the screen's edge. */}
                {/* The conversation keeps the card's surface and loses its inset below lg —
                    `bleed`, and the reason it is allowed, in components/card.tsx. The margins are the
                    composer's own so each edge is stated once; `lg:mx-0` stops there rather than
                    following the composer past the card, which is an older disagreement (not one to
                    copy). */}
            {/* `mb-0` and the composer-less `max-lg:mb-[…]` carry the same specificity, so which one
                a narrow screen gets is decided by emission order — Tailwind writes variants after the
                base, and the conditional wins. That is the one order-dependent thing here: swap
                `max-lg:` for a variant Tailwind emits earlier and this list quietly ends flush against
                the foot of the screen again. (An `!` on `mb-0` would decide it by force, and did,
                which is what silenced this rule until it was measured.) */}
            <Panel
                flush
                bleed
                className={cn('-mx-3 mb-0 sm:-mx-4 lg:mx-0 lg:mb-4', composer === null && 'max-lg:mb-[calc(2rem+var(--modern-bottom-offset))]')}
            >
                {stream.hasOlder && (
                    // The band is the button, not a pill standing inside it. A control alone between
                    // two full-width rules reads as a label that happens to be centred; the whole
                    // strip pressable is the shape a list uses to say "there is more above this".
                    <Button variant="ghost" size="sm" loading={stream.loadingOlder} onClick={loadOlder} className="w-full rounded-none border-b border-border py-3 text-link hover:bg-muted hover:text-link sm:px-5">
                        {t('Load older messages')}
                    </Button>
                )}

                {messages.length === 0 ? (
                    <p className="px-4 py-4 text-sm text-muted-foreground sm:px-5">{t('No messages yet.')}</p>
                ) : (
                    // Not the shared List: nothing rules between these rows, and the list closes its
                    // own foot — see the talk list, whose shape this follows.
                    <ul className="pb-3">
                        {messages.map((message, index) => {
                            // The day said once over the rows that share it — see the talk list,
                            // whose rule this follows. Nothing folds here, so it is the heading alone
                            // rather than a run to hold open as well.
                            const previous = messages[index - 1];
                            const opensDay =
                                previous === undefined || date.siteDay(message.createdAt) !== date.siteDay(previous.createdAt);
                            const above = separatorsAbove({ opensDay, isUnreadBoundary: message.id === dividerId });

                            return (
                            <Fragment key={message.id}>
                                {above.includes('day') && <ChatDayHeading at={message.createdAt} />}
                                {above.includes('unread') && (
                                    // The separator is inside the row rather than being it: a list
                                    // may only hold list items, and an <li role="separator"> is the
                                    // one thing axe's `list` rule refuses. Its label is the whole of
                                    // what it says, so what it draws is hidden. The lead space goes
                                    // to whichever separator is outermost — see the talk list.
                                    <li
                                        data-conversation-divider=""
                                        className={cn('px-4 pb-4 sm:px-5', above[0] === 'unread' ? 'pt-4' : 'pt-0')}
                                    >
                                        <div role="separator" aria-label={t('Unread from here')} className="flex items-center gap-3">
                                            <span aria-hidden className="h-px flex-1 bg-selected/50" />
                                            <span aria-hidden className="text-xs text-selected">
                                                {t('Unread from here')}
                                            </span>
                                            <span aria-hidden className="h-px flex-1 bg-selected/50" />
                                        </div>
                                    </li>
                                )}
                                <ConversationMessageRow
                                    message={message}
                                    highlighted={message.id === highlightId}
                                    separatorAbove={above.length > 0}
                                />
                            </Fragment>
                            );
                        })}
                    </ul>
                )}

                {!atLatest && (
                    <Button variant="ghost" size="sm" loading={stream.loadingNewer} onClick={() => void stream.loadNewer()} className="w-full rounded-none border-t border-border py-3 text-link hover:bg-muted hover:text-link sm:px-5">
                        {t('Load newer messages')}
                    </Button>
                )}
            </Panel>

            {/* Zero-height and sticky rather than fixed: the pill belongs over the conversation, and
                the viewport it would otherwise be centred in is wider than the column at lg. Its foot
                sits one line above the composer, whose height ends in that same bottom offset. */}
            {!atBottom && (
                <div
                    className={cn(
                        // `mb-0`: zero height is not the same as taking no room — see the talk page.
                        'pointer-events-none sticky z-20 mb-0 flex h-0 items-end justify-center',
                        composer === null ? 'bottom-[calc(var(--modern-bottom-offset)+1rem)]' : 'bottom-[calc(var(--modern-bottom-offset)+4.25rem)]',
                    )}
                >
                    <Button size="sm" variant="secondary" onClick={jumpToLatest} className="pointer-events-auto shadow-md">
                        <ArrowDown className="size-4" aria-hidden />
                        {/* What is down there, when the page knows — see the talk page. */}
                        {latestLabel}
                    </Button>
                </div>
            )}

            {composer}
        </>
    );
}
