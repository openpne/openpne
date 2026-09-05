import { Head, usePage } from '@inertiajs/react';
import { ArrowDown, ArrowUp } from 'lucide-react';
import { Fragment, useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { ChatDayHeading } from '@/components/chat-day-heading';
import { ChatScrollDay } from '@/components/chat-scroll-day';
import { useConfirm } from '@/components/confirm-dialog';
import { Button } from '@/components/ui/button';
import { Panel } from '@/components/ui/surface';
import { arrivalsAfter, type ChatSeenMark } from '@/lib/chat/arrivals';
import { chipsWithPending, isPending, noPending, withoutPending, withPending, type PendingReactions, type ReactionOp } from '@/lib/chat/reaction-overlay';
import { foldsInto } from '@/lib/chat/message-grouping';
import { separatorsAbove } from '@/lib/chat/separators';
import { digestPlacement, dividerBeforeId, readThroughBoundary } from '@/lib/chat/unread';
import { useChatStream } from '@/lib/chat/use-chat-stream';
import { useScrollDay } from '@/lib/chat/use-scroll-day';
import { useMarkRead } from '@/lib/chat/use-mark-read';
import { jumpToUnreadPhrase } from '@/lib/count-phrase';
import { xsrfHeader } from '@/lib/csrf';
import { useT } from '@/lib/i18n';
import { useDateFormat } from '@/lib/use-date-format';
import { requestUnreadRefresh } from '@/lib/unread-refresh';
import { cn } from '@/lib/utils';
import type { CommunitySummary } from '@/pages/community/types';
import type { MentionPayloadRow } from '@/lib/mention-draft';
import type { PageProps } from '@/types';
import { TalkComposer } from './composer';
import { TalkMessageRow } from './message-row';
import { TalkMessageSheet } from './message-sheet';
import { TalkMuteToggle } from './mute-toggle';
import { TalkReactorsDialog } from './reactors-dialog';
import { TalkUnreadDigestCard } from './unread-digest';
import type { TalkMessage, TalkPage, TalkUnreadDigest, TalkUnreadSnapshot } from './types';

interface TalkProps extends PageProps {
    group: CommunitySummary;
    page: TalkPage;
    /** The message a `?m=` link opened on; `page` is the slice it sits in. */
    anchor: { messageId: number } | null;
    canPost: boolean;
    isMember: boolean;
    isMuted: boolean;
    talkUnreadSnapshot: TalkUnreadSnapshot | null;
    /** Absent — not null — unless the backlog is large enough to be worth a catch-up card. */
    unreadDigest?: TalkUnreadDigest;
    /**
     * Where the poll starts reading reaction changes from
     * (docs/internals/group-talk.md, "The version is the second watermark").
     */
    reactionsVersion: number;
    /** The one set the site offers (docs/internals/group-talk.md, "Reactions"). */
    reactionVocabulary: string[];
}

const NEAR_BOTTOM_PX = 96;

const HIGHLIGHT_MS = 2_000;

/** One talk list per page, so the message's own id names its row without a container ref. */
const messageElement = (id: number): Element | null => document.querySelector(`[data-talk-message-id="${id}"]`);

const atFoot = (): boolean => window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - NEAR_BOTTOM_PX;

const appendMentions =
    (mentions: MentionPayloadRow[]) =>
    (form: FormData): void => {
        mentions.forEach((mention, index) => {
            form.append(`mentions[${index}][member_id]`, String(mention.member_id));
            form.append(`mentions[${index}][offset]`, String(mention.offset));
            form.append(`mentions[${index}][length]`, String(mention.length));
        });
    };

export default function GroupTalkIndex() {
    const t = useT();
    const date = useDateFormat();
    const confirm = useConfirm();
    const { group, page, anchor, canPost, isMember, isMuted, talkUnreadSnapshot, unreadDigest, reactionsVersion, reactionVocabulary } =
        usePage<TalkProps>().props;
    // Memoized because the stream's poll and reads hang off their identity: a fresh object each render
    // would tear the interval down and start it again.
    const endpoints = useMemo(
        () => ({
            messages: (query: string) => `/groups/${group.id}/talk/messages${query}`,
            send: `/groups/${group.id}/talk`,
            delete: (id: number) => `/groups/${group.id}/talk/messages/${id}/delete`,
        }),
        [group.id],
    );
    const reactionEndpoints = useMemo(
        () => ({
            initialVersion: reactionsVersion,
            add: (id: number) => `/groups/${group.id}/talk/messages/${id}/reactions`,
            remove: (id: number) => `/groups/${group.id}/talk/messages/${id}/reactions/delete`,
        }),
        [group.id, reactionsVersion],
    );
    const stream = useChatStream(endpoints, page, reactionEndpoints);
    const messages = stream.messages;
    const streamSend = stream.send;
    const atLatest = stream.window.kind === 'latest';
    const generation = stream.generation;

    // Reported only from the foot of the live window (docs/internals/group-talk.md, "Mark-read is
    // client-named, server-resolved, and monotonic").
    const [atBottom, setAtBottom] = useState(true);
    useMarkRead(`/groups/${group.id}/talk/read`, messages[messages.length - 1]?.id, isMember && atBottom && atLatest);
    const [seen, setSeen] = useState<ChatSeenMark | null>(null);


    // Membership is deliberately not part of the seen mark: the pill says what has arrived, which a
    // reader with no stored cursor sees as plainly as anyone.
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
    // history restore rewrites (docs/internals/group-talk.md, "The divider is a snapshot, and the
    // banner is what it cannot draw").
    const dividerId = dividerBeforeId(messages, readThroughBoundary(talkUnreadSnapshot), stream.hasOlder);
    const backlog = dividerId === null && talkUnreadSnapshot !== null && talkUnreadSnapshot.count > 0 ? talkUnreadSnapshot : null;

    // The catch-up is one offer from both placements, and spending it is what withdraws it
    // (docs/internals/group-talk.md, "The absence digest").
    const [caughtUp, setCaughtUp] = useState(false);
    const [catchingUp, setCatchingUp] = useState(false);
    const digestAt = digestPlacement(unreadDigest !== undefined, dividerId, backlog !== null, caughtUp);

    // Zero from a history window without a gate of its own: everything loaded there is older than
    // the mark.
    const arrivals = arrivalsAfter(messages, seen);
    // Where the reading area begins, handed to the hook so the offset stays in the class that sets it.
    const scrollDayLine = useRef<HTMLDivElement>(null);
    const scrollDay = useScrollDay('[data-talk-message-id]', scrollDayLine, backlog !== null, () => atFoot() && atLatest);
    const scrollDayAt = scrollDay.index === null ? null : (messages[scrollDay.index]?.createdAt ?? null);

    // `useT` exposes only the string form (lib/i18n), so a count of one is said in the singular here
    // rather than by the library.
    const latestLabel =
        arrivals === 0 ? t('Jump to latest') : arrivals === 1 ? t('1 new message') : t(':count new messages', { count: arrivals });

    // The landing is a ref because the scroll it drives happens once, on mount; the highlight is
    // state because it expires.
    const landing = useRef(anchor?.messageId ?? null);
    const [highlightId, setHighlightId] = useState(landing.current);

    const [replyTo, setReplyTo] = useState<TalkMessage | null>(null);

    // Whether the reader is at the newest message: a conversation that scrolls itself while someone
    // is reading back through it has taken the page away from them.
    const pinned = useRef(true);
    const heldRow = useRef<{ id: number; top: number } | null>(null);
    // A move the reader asked for, spent on the first render whose generation has moved past the one
    // it was asked from (docs/internals/group-talk.md, "Two windows, never mixed").
    const goTo = useRef<{ target: 'divider' | 'bottom'; from: number } | { target: 'anchor'; anchorId: number; from: number } | null>(null);
    // The pin follows new content, not new array identity: a scrollTo re-issued for a merge that
    // moved nothing shoves the sticky composer around under iOS's keyboard pan.
    const tail = useRef<number | undefined>(undefined);

    useEffect(() => {
        const onScroll = () => {
            const foot = atFoot();
            pinned.current = foot;
            // Mirrored into state because mark-read has to re-run when it changes; the ref stays
            // because the scroll-pinning layout effect needs the value without a re-render.
            setAtBottom(foot);
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

    // Nothing re-arms the highlight, so a reader who scrolls away and back finds the message where
    // it was rather than a second flash.
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
            } else if (asked.target === 'anchor') {
                // Set from here, so the row carries the emphasis on its first paint; a parent deleted
                // between render and click leaves no row to find.
                messageElement(asked.anchorId)?.scrollIntoView({ block: 'center' });
                setHighlightId(asked.anchorId);
            } else {
                // Mid-viewport, so the last of what was already read stays visible above the line —
                // landing on the boundary with nothing above it reads as the start of the group.
                document.querySelector('[data-talk-divider]')?.scrollIntoView({ block: 'center' });
            }

            return;
        }

        const held = heldRow.current;
        if (held !== null) {
            heldRow.current = null;
            // History was prepended: put the message the reader was on back where it was, so the
            // page grows upward instead of jumping.
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
    const jumpToContext = (cursor: string, anchorId?: number) => {
        const asked =
            anchorId === undefined
                ? { target: 'divider' as const, from: generation }
                : { target: 'anchor' as const, anchorId, from: generation };
        goTo.current = asked;
        void stream.openContext(cursor).then((moved) => {
            // Only give up the move if it is still the one this click made.
            if (!moved && goTo.current === asked) {
                goTo.current = null;
            }
        });
    };

    const jumpToReply = (parent: { id: number; cursor: string }) => {
        const element = messageElement(parent.id);
        if (element !== null) {
            const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            element.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'center' });
            setHighlightId(parent.id);

            return;
        }

        jumpToContext(parent.cursor, parent.id);
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
            if (!moved && goTo.current === asked) {
                goTo.current = null;
            }
        });
    };

    // The pin is claimed before the write, both because a send always lands you on your own words
    // and because what the write commits may be rendered before this handler resumes.
    const send = async (body: string, mentions: MentionPayloadRow[], images: File[]) => {
        pinned.current = true;
        const parent = replyTo;
        await streamSend(body, images, (form) => {
            appendMentions(mentions)(form);
            // Only when answering something — a present-but-empty reply_to_message_id 422s by design.
            if (parent !== null) {
                form.append('reply_to_message_id', String(parent.id));
            }
        });
        // Reached only when the write lands, and cleared only if it is still the reply just sent: a
        // refusal leaves the staged reply standing, and one staged mid-flight survives.
        setReplyTo((current) => (current === parent ? null : current));
        setAtBottom(true);
    };

    // No message id is this endpoint's "read through the latest", resolved server-side
    // (docs/internals/group-talk.md, "Mark-read is client-named, server-resolved, and monotonic").
    const markAllRead = async () => {
        if (catchingUp) {
            return;
        }

        setCatchingUp(true);
        try {
            const response = await fetch(`/groups/${group.id}/talk/read`, {
                method: 'POST',
                headers: { ...xsrfHeader(), 'Content-Type': 'application/json', Accept: 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({}),
            });

            if (response.ok) {
                setCaughtUp(true);
                requestUnreadRefresh();
            }
        } catch {
            // A network reject leaves the card as it stands: the reader can simply tap again.
        } finally {
            setCatchingUp(false);
        }
    };

    const remove = async (id: number) => {
        if (await confirm({ title: t('Delete this message?'), confirmLabel: t('Delete'), danger: true })) {
            void stream.remove(id);
        }
    };

    // Which row has its picker open is the row's own state, not this page's.
    const [pendingReactions, setPendingReactions] = useState<PendingReactions>(noPending);
    const [reactorsFor, setReactorsFor] = useState<number | null>(null);
    // Stable, because the dialog reads its list once per URL: a fresh closure every render would be
    // a fresh read every poll tick.
    const closeReactors = useCallback(() => setReactorsFor(null), []);

    // Held as an id and resolved against the stream, so a message deleted under the reader takes its
    // sheet with it.
    const [sheetFor, setSheetFor] = useState<number | null>(null);
    const closeSheet = useCallback(() => setSheetFor(null), []);
    const sheetMessage = sheetFor === null ? undefined : messages.find((message) => message.id === sheetFor);

    const toggleReaction = (messageId: number, emoji: string, mine: boolean) => {
        if (isPending(pendingReactions, messageId, emoji)) {
            return;
        }

        const op: ReactionOp = mine ? 'remove' : 'add';
        setPendingReactions((current) => withPending(current, messageId, emoji, op));
        // Settled the same way whichever it was: what the server said is already in the list, and a
        // refusal leaves the row as it stands rather than as it stood when the tap was made.
        const settle = () => setPendingReactions((current) => withoutPending(current, messageId, emoji));
        void stream.react(messageId, emoji, op).then(settle, settle);
    };

    return (
        <>
            <Head title={t('Talk')} />

            {isMember && <TalkMuteToggle groupId={group.id} muted={isMuted} />}

            {/* Withheld while the banner has this slot, and at the foot of the live window
                (docs/internals/group-talk.md, "Date headings, and what stands above a row"). */}
<ChatScrollDay ref={scrollDayLine} at={scrollDayAt} />

            {backlog !== null && (
                // Sticky, because the reader opens at the foot and the boundary this offers is a
                // page or more above them.
                <div className="sticky top-[calc(var(--modern-top-offset)+0.5rem)] z-20 flex justify-center">
                    {digestAt === 'banner' && unreadDigest !== undefined ? (
                        <TalkUnreadDigestCard
                            digest={unreadDigest}
                            onMarkAllRead={markAllRead}
                            marking={catchingUp}
                            onJump={() => jumpToContext(backlog.cursor)}
                            className="w-full max-w-sm shadow-md"
                        />
                    ) : (
                        <Button size="sm" variant="secondary" onClick={() => jumpToContext(backlog.cursor)} className="shadow-md">
                            <ArrowUp className="size-4" aria-hidden />
                            {jumpToUnreadPhrase(t, backlog.count)}
                        </Button>
                    )}
                </div>
            )}

            {/* The composer under this list is not a Card and imports the same edge constant, so the
                two cannot end on different lines (components/card.tsx). */}
            <Panel flush variant="bleed" className="mb-0 lg:mb-4">
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
                            const previous = messages[index - 1];
                            const opensDay =
                                previous === undefined || date.siteDay(message.createdAt) !== date.siteDay(previous.createdAt);
                            const above = separatorsAbove({ opensDay, isUnreadBoundary: message.id === dividerId });
                            // Whatever stands above the row holds its run open (message-grouping):
                            // the row says again who is speaking.
                            const restartsHere = above.length > 0;

                            return (
                            <Fragment key={message.id}>
                                {above.includes('day') && <ChatDayHeading at={message.createdAt} />}
                                {above.includes('unread') && (
                                    // Inside the row rather than being it: an <li role="separator">
                                    // is the one thing axe's `list` rule refuses.
                                    <li
                                        data-talk-divider=""
                                        className={cn('px-4 pb-4 sm:px-5', above[0] === 'unread' ? 'pt-4' : 'pt-0')}
                                    >
                                        <div role="separator" aria-label={t('Unread from here')} className="flex items-center gap-3">
                                            <span aria-hidden className="h-px flex-1 bg-selected/50" />
                                            <span aria-hidden className="text-xs text-selected">
                                                {t('Unread from here')}
                                            </span>
                                            <span aria-hidden className="h-px flex-1 bg-selected/50" />
                                        </div>
                                        {digestAt === 'divider' && unreadDigest !== undefined && (
                                            // No jump here: the reader is already standing on the
                                            // boundary.
                                            <TalkUnreadDigestCard
                                                digest={unreadDigest}
                                                onMarkAllRead={markAllRead}
                                                marking={catchingUp}
                                                // Tinted, because here the card lies on the panel's
                                                // own surface: at bg-card it would be a box drawn on
                                                // its own colour, with only a hairline to find it by.
                                                className="mt-2 bg-muted/40"
                                            />
                                        )}
                                    </li>
                                )}
                                <TalkMessageRow
                                    message={message}
                                    onDelete={remove}
                                    onOpenActions={() => setSheetFor(message.id)}
                                    onReply={() => setReplyTo(message)}
                                    onJumpToReply={jumpToReply}
                                    canReply={canPost}
                                    highlighted={message.id === highlightId}
                                    grouped={foldsInto(previous, message, restartsHere)}
                                    separatorAbove={restartsHere}
                                    reactions={{
                                        chips: chipsWithPending(message.reactions ?? [], pendingReactions, message.id),
                                        vocabulary: reactionVocabulary,
                                        canReact: canPost,
                                        onToggle: (emoji, mine) => toggleReaction(message.id, emoji, mine),
                                        onShowReactors: () => setReactorsFor(message.id),
                                    }}
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
                // `mb-0` because zero height still takes the margin the page puts under every child
                // but the last, which would cost the pill 16px of page each time it appears.
                <div className="pointer-events-none sticky bottom-[calc(var(--modern-bottom-offset)+4.25rem)] z-20 mb-0 flex h-0 items-end justify-center">
                    <Button size="sm" variant="secondary" onClick={jumpToLatest} className="pointer-events-auto shadow-md">
                        <ArrowDown className="size-4" aria-hidden />
                        {/* A separate word from the banner's "unread" above: that one is the server's
                            cursor, this one is what has landed since the reader was last at the
                            foot. */}
                        {latestLabel}
                    </Button>
                </div>
            )}

            {reactorsFor !== null && (
                <TalkReactorsDialog url={`/groups/${group.id}/talk/messages/${reactorsFor}/reactions`} onClose={closeReactors} />
            )}

            {sheetMessage !== undefined && (
                <TalkMessageSheet
                    message={sheetMessage}
                    chips={chipsWithPending(sheetMessage.reactions ?? [], pendingReactions, sheetMessage.id)}
                    vocabulary={reactionVocabulary}
                    canReact={canPost}
                    canReply={canPost}
                    onToggle={(emoji, mine) => toggleReaction(sheetMessage.id, emoji, mine)}
                    onShowReactors={() => setReactorsFor(sheetMessage.id)}
                    onReply={() => setReplyTo(sheetMessage)}
                    onDelete={() => void remove(sheetMessage.id)}
                    onClose={closeSheet}
                />
            )}

            {canPost ? (
                <TalkComposer groupId={group.id} groupName={group.name} replyTo={replyTo} onCancelReply={() => setReplyTo(null)} onSend={send} />
            ) : (
                // With no composer standing on the screen's foot, this line takes back both the
                // padding the frame gives it and the home-indicator strip.
                <p className="text-sm text-muted-foreground max-lg:pb-[calc(2rem+var(--modern-bottom-offset))]">
                    {t('Join this %community% to post.')}
                </p>
            )}
        </>
    );
}
