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
import { jumpToUnreadPhrase } from '@/lib/count-phrase';
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
    /** The message a `?m=` link opened on; `page` is the slice it sits in. */
    anchor: number | null;
    unreadSnapshot: ConversationUnreadSnapshot | null;
    /** Whether this conversation may be written to; false makes it read-only, not merely composer-less. */
    canSend: boolean;
}

const NEAR_BOTTOM_PX = 96;

const HIGHLIGHT_MS = 2_000;

/** One conversation list per page, so the message's own id names its row without a container ref. */
const messageElement = (id: number): Element | null => document.querySelector(`[data-conversation-message-id="${id}"]`);

const atFoot = (): boolean => window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - NEAR_BOTTOM_PX;

export default function MessageConversation() {
    const t = useT();
    const date = useDateFormat();
    const { counterpart, page, anchor, unreadSnapshot, canSend } = usePage<ConversationProps>().props;
    // A departed member leaves no id to key a conversation by, so every one of them is addressed by
    // the bucket's own literal.
    const path = `/messages/${counterpart?.id ?? 'withdrawn'}`;
    // Memoized because the stream's poll and reads hang off their identity: a fresh object each render
    // would tear the interval down and start it again.
    const endpoints = useMemo(
        () => ({ messages: (query: string) => `${path}/messages${query}`, send: canSend ? path : undefined }),
        [path, canSend],
    );
    const stream = useChatStream(endpoints, page);
    const messages = stream.messages;
    const streamSend = stream.send;
    const atLatest = stream.window.kind === 'latest';
    const generation = stream.generation;

    // Reported only from the foot of the live window (docs/internals/direct-messages.md, "Mark-read").
    const [atBottom, setAtBottom] = useState(true);
    useMarkRead(`${path}/read`, messages[messages.length - 1]?.id, atBottom && atLatest);
    const [seen, setSeen] = useState<ChatSeenMark | null>(null);


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

    // The line and the banner below come off the render-time snapshot, which only the reload after a
    // history restore rewrites (docs/internals/direct-messages.md, "Unread").
    const dividerId = dividerBeforeId(messages, firstUnreadBoundary(unreadSnapshot), stream.hasOlder);
    const backlog = dividerId === null && unreadSnapshot !== null ? unreadSnapshot : null;
    const arrivals = arrivalsAfter(messages, seen);
    // Where the reading area begins, handed to the hook so the offset stays in the class that sets it.
    const scrollDayLine = useRef<HTMLDivElement>(null);
    const scrollDay = useScrollDay('[data-conversation-message-id]', scrollDayLine, backlog !== null, () => atFoot() && atLatest);
    const scrollDayAt = scrollDay.index === null ? null : (messages[scrollDay.index]?.createdAt ?? null);

    // `useT` exposes only the string form (lib/i18n), so a count of one is said in the singular here
    // rather than by the library.
    const latestLabel =
        arrivals === 0 ? t('Jump to latest') : arrivals === 1 ? t('1 new message') : t(':count new messages', { count: arrivals });

    // The landing is a ref because the scroll it drives happens once, on mount; the highlight is
    // state because it expires.
    const landing = useRef(anchor);
    const [highlightId, setHighlightId] = useState(landing.current);

    // A conversation that scrolls itself while someone is reading back through it has taken the page
    // away from them, so new arrivals only follow the reader who is already at the foot.
    const pinned = useRef(true);
    const heldRow = useRef<{ id: number; top: number } | null>(null);
    // A move the reader asked for, spent on the first render whose generation has moved past the one
    // it was asked from (docs/internals/group-talk.md, "Two windows, never mixed").
    const goTo = useRef<{ target: 'divider' | 'bottom'; from: number } | null>(null);
    // The pin follows new content, not new array identity: merges rebuild the list for reasons that
    // move nothing.
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

    // Nothing re-arms the highlight, so a reader who scrolls away and back finds the message where it
    // was rather than a second flash.
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

    // The move is claimed before the read, since what it lands on may be committed before this
    // handler resumes; a read that brings nothing back gives it up again.
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

    // The pin is claimed before the write, both because a send always lands you on your own words and
    // because what the write commits may be rendered before this handler resumes.
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

            {/* Withheld while the banner has this slot, and at the foot of the live window
                (docs/internals/group-talk.md, "Date headings, and what stands above a row"). */}
            <ChatScrollDay ref={scrollDayLine} at={scrollDayAt} />

            {backlog !== null && (
                // Sticky, because the reader opens at the foot and the boundary this offers is a
                // page or more above them.
                <div className="sticky top-[calc(var(--modern-top-offset)+0.5rem)] z-20 flex justify-center">
                    <Button size="sm" variant="secondary" onClick={() => jumpToContext(backlog.cursor)} className="shadow-md">
                        <ArrowUp className="size-4" aria-hidden />
                        {jumpToUnreadPhrase(t, backlog.count)}
                    </Button>
                </div>
            )}

            {/* With no composer standing on the page's foot, the list takes back both that rhythm and
                the home-indicator strip the shell leaves the composer, rather than ending the page on
                the screen's edge. */}
            {/* The composer under this list is not a Card and imports the same edge constant, so the
                two cannot end on different lines (components/card.tsx). */}
            {/* `mb-0` and the composer-less `max-lg:mb-[…]` tie on specificity, so the conditional
                wins only because Tailwind emits variants after the base. */}
            <Panel
                flush
                variant="bleed"
                className={cn('mb-0 lg:mb-4', composer === null && 'max-lg:mb-[calc(2rem+var(--modern-bottom-offset))]')}
            >
                {stream.hasOlder && (
                    <Button variant="ghost" size="sm" loading={stream.loadingOlder} onClick={loadOlder} className="w-full rounded-none border-b border-border py-3 text-link hover:bg-muted hover:text-link sm:px-5">
                        {t('Load older messages')}
                    </Button>
                )}

                {messages.length === 0 ? (
                    <p className="px-4 py-4 text-sm text-muted-foreground sm:px-5">{t('No messages yet.')}</p>
                ) : (
                    // Not the shared List: nothing rules between these rows
                    // (docs/internals/group-talk.md, "Date headings, and what stands above a row").
                    <ul className="pb-3">
                        {messages.map((message, index) => {
                            // Nothing folds here, so it is the heading alone rather than a run to
                            // hold open as well.
                            const previous = messages[index - 1];
                            const opensDay =
                                previous === undefined || date.siteDay(message.createdAt) !== date.siteDay(previous.createdAt);
                            const above = separatorsAbove({ opensDay, isUnreadBoundary: message.id === dividerId });

                            return (
                            <Fragment key={message.id}>
                                {above.includes('day') && <ChatDayHeading at={message.createdAt} />}
                                {above.includes('unread') && (
                                    // Inside the row rather than being it: an <li role="separator">
                                    // is the one thing axe's `list` rule refuses.
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

            {/* Sticky rather than fixed: what fixed would centre the pill in is the viewport, which
                is wider than the column at lg. */}
            {!atBottom && (
                <div
                    className={cn(
                        // `mb-0` because zero height still takes the margin the page puts under every
                        // child but the last.
                        'pointer-events-none sticky z-20 mb-0 flex h-0 items-end justify-center',
                        composer === null ? 'bottom-[calc(var(--modern-bottom-offset)+1rem)]' : 'bottom-[calc(var(--modern-bottom-offset)+4.25rem)]',
                    )}
                >
                    <Button size="sm" variant="secondary" onClick={jumpToLatest} className="pointer-events-auto shadow-md">
                        <ArrowDown className="size-4" aria-hidden />
                        {latestLabel}
                    </Button>
                </div>
            )}

            {composer}
        </>
    );
}
