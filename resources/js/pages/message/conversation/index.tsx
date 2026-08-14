import { Head, usePage } from '@inertiajs/react';
import { ArrowDown } from 'lucide-react';
import { useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { List, Panel } from '@/components/ui/surface';
import { useChatStream } from '@/lib/chat/use-chat-stream';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { MessageMember } from '../types';
import { ConversationMessageRow } from './message-row';
import type { ConversationPage } from './types';

interface ConversationProps extends PageProps {
    /** Who the conversation is with; null is the bucket every withdrawn member's messages fall into. */
    counterpart: MessageMember | null;
    page: ConversationPage;
    /** The message a `?m=` link opened on; null for an ordinary visit. `page` is the slice it sits in. */
    anchor: number | null;
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
    const { counterpart, page, anchor } = usePage<ConversationProps>().props;
    // Memoized because the stream's poll and reads hang off their identity: rebuilt every render, the
    // interval would be torn down and started again each time the page re-rendered.
    const endpoints = useMemo(
        // Read-only for now, so the stream is handed no write endpoints at all.
        () => ({ messages: (query: string) => `/messages/${counterpart?.id ?? 'withdrawn'}/messages${query}` }),
        [counterpart?.id],
    );
    const stream = useChatStream(endpoints, page);
    const messages = stream.messages;
    const atLatest = stream.window.kind === 'latest';
    const generation = stream.generation;

    // Whether the reader is standing at the newest message, which is what the "jump to latest" pill
    // answers to.
    const [atBottom, setAtBottom] = useState(true);

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
    const goTo = useRef<{ from: number } | null>(null);
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
            window.scrollTo({ top: document.documentElement.scrollHeight });

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

    // From the live window this is a scroll; from a history window the newest messages are not even
    // loaded, so it is a read first and the scroll lands on what comes back. The move is claimed
    // before the read, since the state it lands on may be committed before this handler resumes.
    const jumpToLatest = () => {
        pinned.current = true;

        if (atLatest) {
            window.scrollTo({ top: document.documentElement.scrollHeight });

            return;
        }

        const asked = { from: generation };
        goTo.current = asked;
        void stream.returnToLatest().then((moved) => {
            // Only give up the move if it is still the one this click made.
            if (!moved && goTo.current === asked) {
                goTo.current = null;
            }
        });
    };

    return (
        <>
            <Head title={counterpart?.name ?? t('Withdrawn member')} />

            {/* The frame gives its bottom padding up to the composer a talk stands on its foot; with
                nothing to stand there, the list takes the rhythm back rather than ending the page on
                the screen's edge. */}
            <Panel flush className="max-lg:mb-8">
                {stream.hasOlder && (
                    <div className="flex justify-center border-b border-border px-4 py-2 sm:px-5">
                        <Button variant="ghost" size="sm" loading={stream.loadingOlder} onClick={loadOlder}>
                            {t('Load older messages')}
                        </Button>
                    </div>
                )}

                {messages.length === 0 ? (
                    <p className="px-4 py-4 text-sm text-muted-foreground sm:px-5">{t('No messages yet.')}</p>
                ) : (
                    <List>
                        {messages.map((message) => (
                            <ConversationMessageRow key={message.id} message={message} highlighted={message.id === highlightId} />
                        ))}
                    </List>
                )}

                {!atLatest && (
                    <div className="flex justify-center border-t border-border px-4 py-2 sm:px-5">
                        <Button variant="ghost" size="sm" loading={stream.loadingNewer} onClick={() => void stream.loadNewer()}>
                            {t('Load newer messages')}
                        </Button>
                    </div>
                )}
            </Panel>

            {/* Zero-height and sticky rather than fixed: the pill belongs over the conversation, and
                the viewport it would otherwise be centred in is wider than the column at lg. */}
            {!atBottom && (
                <div className="pointer-events-none sticky bottom-[calc(var(--modern-bottom-offset)+1rem)] z-20 flex h-0 items-end justify-center">
                    <Button size="sm" variant="secondary" onClick={jumpToLatest} className="pointer-events-auto shadow-md">
                        <ArrowDown className="size-4" aria-hidden />
                        {t('Jump to latest')}
                    </Button>
                </div>
            )}
        </>
    );
}
