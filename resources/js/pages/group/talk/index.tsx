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
    /** The message a `?m=` link opened on; null for an ordinary visit. `page` is the slice it sits in. */
    anchor: { messageId: number } | null;
    canPost: boolean;
    isMember: boolean;
    isMuted: boolean;
    talkUnreadSnapshot: TalkUnreadSnapshot | null;
    /** Absent — not null — unless the backlog is large enough to be worth a catch-up card. */
    unreadDigest?: TalkUnreadDigest;
    /** Where the poll starts reading reaction changes from — see the second watermark in use-chat-stream.ts. */
    reactionsVersion: number;
    /** What this site offers, shipped by the page so nothing in the bundle holds a second copy. */
    reactionVocabulary: string[];
}

/** How close to the foot still counts as reading the newest message. */
const NEAR_BOTTOM_PX = 96;

/** How long the message a deep link landed on stays picked out. */
const HIGHLIGHT_MS = 2_000;

/** One talk list per page, so the message's own id names its row without a container ref. */
const messageElement = (id: number): Element | null => document.querySelector(`[data-talk-message-id="${id}"]`);

/** Whether the reader is standing at the foot of what is loaded. */
const atFoot = (): boolean => window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - NEAR_BOTTOM_PX;

/** Talk's own multipart fields: the mention ranges the composer resolved over the body being sent. */
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
    // Memoized because the stream's poll and reads hang off their identity: rebuilt every render, the
    // interval would be torn down and started again each time the page re-rendered.
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

    // Reading is being at the foot of the conversation. Someone scrolled back through history has
    // not read what just arrived below them, so their cursor stays where it is — and the foot of a
    // history window is not the foot of the conversation at all.
    const [atBottom, setAtBottom] = useState(true);
    useMarkRead(`/groups/${group.id}/talk/read`, messages[messages.length - 1]?.id, isMember && atBottom && atLatest);
    const [seen, setSeen] = useState<ChatSeenMark | null>(null);


    // Where the reader has actually stood. The pill's count is what has arrived past it, so it moves
    // on the same fact mark-read moves on — being at the foot of the live window — and on nothing
    // else. Not folded into the arrival effect below: the pill itself puts a reader back at the foot
    // without changing the list, and an effect keyed on the messages would never notice.
    //
    // Membership is deliberately not part of it. The pill says what has *arrived*, which a reader
    // with no stored cursor sees as plainly as anyone; the read cursor is a different claim.
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
    // lands on it long after the stored one has moved to the foot of the conversation. The one
    // writer that does reach the snapshot is the reload after a history restore
    // (lib/revalidate-on-restore.ts), deliberately: a restore is a fresh arrival, and the line
    // belongs where a fresh visit would draw it.
    const dividerId = dividerBeforeId(messages, readThroughBoundary(talkUnreadSnapshot), stream.hasOlder);
    // The backlog the page cannot draw a line for, because the boundary is further back than it has
    // loaded. Null when the line is on screen, or when there was nothing waiting to begin with.
    const backlog = dividerId === null && talkUnreadSnapshot !== null && talkUnreadSnapshot.count > 0 ? talkUnreadSnapshot : null;

    // The catch-up, and whether it has been taken. Both live here rather than in the card because the
    // card is drawn in either of two places and it is one offer from both — and because spending it
    // is what withdraws it: the digest is a render-time snapshot like the divider, so nothing
    // re-reads it mid-visit to notice the backlog is gone.
    const [caughtUp, setCaughtUp] = useState(false);
    const [catchingUp, setCatchingUp] = useState(false);
    const digestAt = digestPlacement(unreadDigest !== undefined, dividerId, backlog !== null, caughtUp);

    // Zero from a history window without a gate of its own: everything loaded there is older than
    // the mark, so nothing counts as having arrived past it.
    const arrivals = arrivalsAfter(messages, seen);
    // Where the reading area begins, handed to the hook so the offset stays in the class that sets it.
    const scrollDayLine = useRef<HTMLDivElement>(null);
    const scrollDay = useScrollDay('[data-talk-message-id]', scrollDayLine, backlog !== null, () => atFoot() && atLatest);
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
    const landing = useRef(anchor?.messageId ?? null);
    const [highlightId, setHighlightId] = useState(landing.current);

    // The message a new post answers: a row or the action sheet stages it, the composer shows it, and
    // the send consumes its id and clears it. Null when the next post answers nothing.
    const [replyTo, setReplyTo] = useState<TalkMessage | null>(null);

    // Whether the reader is at the newest message. A conversation that scrolls itself while someone
    // is reading back through it has taken the page away from them.
    const pinned = useRef(true);
    // The message "load older" was standing on, and where it sat in the viewport.
    const heldRow = useRef<{ id: number; top: number } | null>(null);
    // A move the reader asked for, and the list it was asked from. It is spent on the render that
    // carries its own answer — the generation moves only when a window change lands, so a poll or a
    // merge arriving in between cannot spend it and leave the jump un-made. `anchor` also names the
    // row to center and pick out once its stretch lands — a reply header's jump to an off-screen parent.
    const goTo = useRef<{ target: 'divider' | 'bottom'; from: number } | { target: 'anchor'; anchorId: number; from: number } | null>(null);
    // The newest message the pin last answered. The pin follows new content, not new array
    // identity: merges rebuild the list for reasons that move nothing (a re-read row), and a
    // scrollTo re-issued then shoves the sticky composer around under iOS's keyboard pan.
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

    // The emphasis is temporary; the landing is not. Nothing re-arms it, so a reader who scrolls
    // away and back finds the message where it was rather than flashing again.
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
                // The stretch landed around the parent's position. Center and pick it out — set from
                // here, so the row carries the emphasis on its first paint like a deep link's landing.
                // A parent deleted between render and click leaves no row and the highlight finds none.
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

    // The move is claimed before the read, since the state it lands on may be committed before this
    // handler resumes; a read that brings nothing back gives it up again rather than leaving a jump
    // armed for whatever changes the list next. With `anchorId` the landing centers and highlights
    // that row (a reply header's off-screen parent) instead of the unread divider (the banner's jump).
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

    // A reply header's jump to the message it answers. On screen it is a scroll and a transient
    // highlight, the same emphasis a deep link's landing draws; off screen it is a context read that
    // brings the parent's stretch into view, then the same highlight once it lands (jumpToContext).
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
            if (!moved && goTo.current === asked) {
                goTo.current = null;
            }
        });
    };

    // Your own send always lands you on your own words. The pinned gate protects someone reading
    // back through history from being yanked by *others'* arrivals; writing is the opposite intent —
    // and it is claimed before the write, because what the write commits may be rendered before this
    // handler is resumed to say so.
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
        // Reached only when the write lands: a refusal throws out of streamSend with the draft — and
        // its staged reply — left standing for the member to resend or take back. Cleared only if it
        // is still the reply just sent: a member who staged a new one while this was in flight keeps
        // it, the last intent winning as it does for a jump.
        setReplyTo((current) => (current === parent ? null : current));
        setAtBottom(true);
    };

    // The catch-up: no message id, which is this endpoint's "read through the latest" — and the
    // latest is resolved server-side at the moment the cursor moves, so a message landing mid-request
    // stays unread (App\Features\GroupTalk\Actions\MarkTalkRead). The badge is not patched from here;
    // the shell owns it and is only asked to re-read. The card is withdrawn on the answer rather than
    // on the click, so a refusal leaves the offer standing instead of hiding a backlog still waiting.
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

    // The taps still on the wire, and the message whose reactor list is being read. Which row has its
    // picker open is the row's own business — see the picker.
    const [pendingReactions, setPendingReactions] = useState<PendingReactions>(noPending);
    const [reactorsFor, setReactorsFor] = useState<number | null>(null);
    // Stable, because the dialog reads its list once per URL: a fresh closure every render would be
    // a fresh read every poll tick.
    const closeReactors = useCallback(() => setReactorsFor(null), []);

    // The message a press opened the action sheet on, held as an id and resolved against the stream:
    // one deleted under the reader — by its author elsewhere, by the poll — takes its sheet with it
    // rather than leaving actions standing over a message that is gone.
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
                    {digestAt === 'banner' && unreadDigest !== undefined ? (
                        // The card stands in for the banner and carries its jump, so catching up and
                        // going to look are still the same two choices they were.
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
                            {t('Jump to :count unread messages', { count: backlog.count })}
                        </Button>
                    )}
                </div>
            )}

                {/* The conversation keeps the card's surface and loses its inset below lg —
                    `bleed`, and the reason it is allowed, in components/card.tsx. The margins are the
                    composer's own so each edge is stated once; `lg:mx-0` stops there rather than
                    following the composer past the card, which is an older disagreement (not one to
                    copy). */}
            <Panel flush bleed className="-mx-3 mb-0 sm:-mx-4 lg:mx-0 lg:mb-4">
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
                    // Not the shared List: nothing rules between these rows. A turn is told from the
                    // one before it by the space above it (see the row), and the only line left in
                    // the conversation is the unread band, which is a different kind of claim.
                    //
                    // The list closes the foot itself. A row leaves almost nothing under it because
                    // the next row's own space is what follows — and the last row has no next row,
                    // while the foot is where every visit opens.
                    <ul className="pb-3">
                        {messages.map((message, index) => {
                            // The day is said once over the rows that share it. Drawn above the first
                            // row too, unlike the unread line: a heading claims only that the rows
                            // under it are of that day, which stays true however far back the history
                            // reaches — and when "load older" prepends more of the same day, the row
                            // that carried it stops matching and the heading moves up with them.
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
                                    // The separator is inside the row rather than being it: a list
                                    // may only hold list items, and an <li role="separator"> is the
                                    // one thing axe's `list` rule refuses. Its label is the whole of
                                    // what it says, so what it draws is hidden.
                                    //
                                    // The lead space goes to whichever separator is outermost (see
                                    // the day heading): where the two meet they are one block with
                                    // room around it, not two with room between them.
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
                                            // Under the line, at the head of what was missed. No jump
                                            // here: the reader is already standing on the boundary.
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

            {/* Zero-height and sticky rather than fixed: the pill belongs over the conversation, and
                the viewport it would otherwise be centred in is wider than the column at lg. Its foot
                sits one line above the composer, whose height ends in that same bottom offset.

                `mb-0` because zero height is not the same as taking no room: the page's reading rhythm
                puts a margin under every child but the last, and this wrapper is one — so without it
                the pill costs 16px of page each time it appears, which is a strange thing for
                something drawn at zero height to do. */}
            {!atBottom && (
                <div className="pointer-events-none sticky bottom-[calc(var(--modern-bottom-offset)+4.25rem)] z-20 mb-0 flex h-0 items-end justify-center">
                    <Button size="sm" variant="secondary" onClick={jumpToLatest} className="pointer-events-auto shadow-md">
                        <ArrowDown className="size-4" aria-hidden />
                        {/* What is down there, when the page knows: a reader scrolled up is deciding
                            whether to go back, and "latest" alone does not say whether anything has
                            happened. A separate word from the banner's "unread" above, because they
                            are separate claims — that one is the server's cursor, this one is what
                            has landed since the reader was last at the foot. */}
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
                // The frame gives its bottom padding up to the composer standing on the screen's foot,
                // and the shell leaves it the home-indicator strip; with no composer to stand there,
                // this line takes both back rather than ending the page on the screen's edge.
                <p className="text-sm text-muted-foreground max-lg:pb-[calc(2rem+var(--modern-bottom-offset))]">
                    {t('Join this %community% to post.')}
                </p>
            )}
        </>
    );
}
