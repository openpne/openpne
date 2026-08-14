import { Head, usePage } from '@inertiajs/react';
import { ArrowDown, ArrowUp } from 'lucide-react';
import { Fragment, useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { useConfirm } from '@/components/confirm-dialog';
import { Button } from '@/components/ui/button';
import { List, Panel } from '@/components/ui/surface';
import { chipsWithPending, isPending, noPending, withoutPending, withPending, type PendingReactions, type ReactionOp } from '@/lib/chat/reaction-overlay';
import { dividerBeforeId, readThroughBoundary } from '@/lib/chat/unread';
import { useChatStream } from '@/lib/chat/use-chat-stream';
import { useMarkRead } from '@/lib/chat/use-mark-read';
import { useT } from '@/lib/i18n';
import type { CommunitySummary } from '@/pages/community/types';
import type { MentionPayloadRow } from '@/lib/mention-draft';
import type { PageProps } from '@/types';
import { TalkComposer } from './composer';
import { TalkMessageRow } from './message-row';
import { TalkMuteToggle } from './mute-toggle';
import { TalkReactorsDialog } from './reactors-dialog';
import type { TalkPage, TalkUnreadSnapshot } from './types';

interface TalkProps extends PageProps {
    group: CommunitySummary;
    page: TalkPage;
    /** The message a `?m=` link opened on; null for an ordinary visit. `page` is the slice it sits in. */
    anchor: { messageId: number } | null;
    canPost: boolean;
    isMember: boolean;
    isMuted: boolean;
    talkUnreadSnapshot: TalkUnreadSnapshot | null;
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
    const confirm = useConfirm();
    const { group, page, anchor, canPost, isMember, isMuted, talkUnreadSnapshot, reactionsVersion, reactionVocabulary } =
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

    // The line the visit opened on. Both this and the banner below come off the render-time snapshot
    // and nothing else — which is what makes them survive the mark-read that fires seconds later.
    // The snapshot's cursor is the boundary as a position, so the jump still lands on it long after
    // the stored one has moved to the foot of the conversation.
    const dividerId = dividerBeforeId(messages, readThroughBoundary(talkUnreadSnapshot), stream.hasOlder);
    // The backlog the page cannot draw a line for, because the boundary is further back than it has
    // loaded. Null when the line is on screen, or when there was nothing waiting to begin with.
    const backlog = dividerId === null && talkUnreadSnapshot !== null && talkUnreadSnapshot.count > 0 ? talkUnreadSnapshot : null;

    // The message this visit opened on, and its emphasis. The landing is a ref because it describes
    // the arrival rather than the render — the scroll it drives happens once, on mount — while the
    // highlight is state because it expires.
    const landing = useRef(anchor?.messageId ?? null);
    const [highlightId, setHighlightId] = useState(landing.current);

    // Whether the reader is at the newest message. A conversation that scrolls itself while someone
    // is reading back through it has taken the page away from them.
    const pinned = useRef(true);
    // The message "load older" was standing on, and where it sat in the viewport.
    const heldRow = useRef<{ id: number; top: number } | null>(null);
    // A move the reader asked for, and the list it was asked from. It is spent on the render that
    // carries its own answer — the generation moves only when a window change lands, so a poll or a
    // merge arriving in between cannot spend it and leave the jump un-made.
    const goTo = useRef<{ target: 'divider' | 'bottom'; from: number } | null>(null);
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
        await streamSend(body, images, appendMentions(mentions));
        setAtBottom(true);
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

            <Panel flush>
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
                            <Fragment key={message.id}>
                                {message.id === dividerId && (
                                    // The separator is inside the row rather than being it: a list
                                    // may only hold list items, and an <li role="separator"> is the
                                    // one thing axe's `list` rule refuses. Its label is the whole of
                                    // what it says, so what it draws is hidden.
                                    <li data-talk-divider="" className="px-4 py-2 sm:px-5">
                                        <div role="separator" aria-label={t('Unread from here')} className="flex items-center gap-3">
                                            <span aria-hidden className="h-px flex-1 bg-selected/50" />
                                            <span aria-hidden className="text-xs text-selected">
                                                {t('Unread from here')}
                                            </span>
                                            <span aria-hidden className="h-px flex-1 bg-selected/50" />
                                        </div>
                                    </li>
                                )}
                                <TalkMessageRow
                                    message={message}
                                    onDelete={remove}
                                    highlighted={message.id === highlightId}
                                    reactions={{
                                        chips: chipsWithPending(message.reactions ?? [], pendingReactions, message.id),
                                        vocabulary: reactionVocabulary,
                                        canReact: canPost,
                                        onToggle: (emoji, mine) => toggleReaction(message.id, emoji, mine),
                                        onShowReactors: () => setReactorsFor(message.id),
                                    }}
                                />
                            </Fragment>
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
                the viewport it would otherwise be centred in is wider than the column at lg. Its foot
                sits one line above the composer, whose height ends in that same bottom offset. */}
            {!atBottom && (
                <div className="pointer-events-none sticky bottom-[calc(var(--modern-bottom-offset)+4.25rem)] z-20 flex h-0 items-end justify-center">
                    <Button size="sm" variant="secondary" onClick={jumpToLatest} className="pointer-events-auto shadow-md">
                        <ArrowDown className="size-4" aria-hidden />
                        {t('Jump to latest')}
                    </Button>
                </div>
            )}

            {reactorsFor !== null && (
                <TalkReactorsDialog url={`/groups/${group.id}/talk/messages/${reactorsFor}/reactions`} onClose={closeReactors} />
            )}

            {canPost ? (
                <TalkComposer groupId={group.id} groupName={group.name} onSend={send} />
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
