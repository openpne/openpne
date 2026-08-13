import { Head, usePage } from '@inertiajs/react';
import { ArrowDown, ArrowUp } from 'lucide-react';
import { Fragment, useEffect, useLayoutEffect, useRef, useState } from 'react';
import { useConfirm } from '@/components/confirm-dialog';
import { Button } from '@/components/ui/button';
import { List, Panel } from '@/components/ui/surface';
import { useT } from '@/lib/i18n';
import type { CommunitySummary } from '@/pages/community/types';
import type { MentionPayloadRow } from '@/lib/mention-draft';
import type { PageProps } from '@/types';
import { TalkComposer } from './composer';
import { TalkMessageRow } from './message-row';
import { TalkMuteToggle } from './mute-toggle';
import { dividerBeforeId } from './talk-unread';
import type { TalkPage, TalkUnreadSnapshot } from './types';
import { useMarkRead } from './use-mark-read';
import { useTalkStream } from './use-talk-stream';

interface TalkProps extends PageProps {
    group: CommunitySummary;
    page: TalkPage;
    canPost: boolean;
    isMember: boolean;
    isMuted: boolean;
    talkUnreadSnapshot: TalkUnreadSnapshot | null;
}

/** How close to the foot still counts as reading the newest message. */
const NEAR_BOTTOM_PX = 96;

/** One talk list per page, so the message's own id names its row without a container ref. */
const messageElement = (id: number): Element | null => document.querySelector(`[data-talk-message-id="${id}"]`);

export default function GroupTalkIndex() {
    const t = useT();
    const confirm = useConfirm();
    const { group, page, canPost, isMember, isMuted, talkUnreadSnapshot } = usePage<TalkProps>().props;
    const stream = useTalkStream(group.id, page);
    const messages = stream.messages;
    const streamSend = stream.send;
    const atLatest = stream.window.kind === 'latest';
    const generation = stream.generation;

    // Reading is being at the foot of the conversation. Someone scrolled back through history has
    // not read what just arrived below them, so their cursor stays where it is — and the foot of a
    // history window is not the foot of the conversation at all.
    const [atBottom, setAtBottom] = useState(true);
    useMarkRead(group.id, messages[messages.length - 1]?.id, isMember && atBottom && atLatest);

    // The line the visit opened on. Both this and the banner below come off the render-time snapshot
    // and nothing else — which is what makes them survive the mark-read that fires seconds later.
    // The snapshot's cursor is the boundary as a position, so the jump still lands on it long after
    // the stored one has moved to the foot of the conversation.
    const dividerId = dividerBeforeId(messages, talkUnreadSnapshot, stream.hasOlder);
    // The backlog the page cannot draw a line for, because the boundary is further back than it has
    // loaded. Null when the line is on screen, or when there was nothing waiting to begin with.
    const backlog = dividerId === null && talkUnreadSnapshot !== null && talkUnreadSnapshot.count > 0 ? talkUnreadSnapshot : null;

    // Whether the reader is at the newest message. A conversation that scrolls itself while someone
    // is reading back through it has taken the page away from them.
    const pinned = useRef(true);
    // The message "load older" was standing on, and where it sat in the viewport.
    const anchor = useRef<{ id: number; top: number } | null>(null);
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
            const foot = window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - NEAR_BOTTOM_PX;
            pinned.current = foot;
            // Mirrored into state because mark-read has to re-run when it changes; the ref stays
            // because the scroll-pinning layout effect needs the value without a re-render.
            setAtBottom(foot);
        };
        window.addEventListener('scroll', onScroll, { passive: true });

        return () => window.removeEventListener('scroll', onScroll);
    }, []);

    // Open on the newest message, the way a conversation is read.
    useLayoutEffect(() => {
        window.scrollTo({ top: document.documentElement.scrollHeight });
    }, []);

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

        const held = anchor.current;
        if (held !== null) {
            anchor.current = null;
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
        anchor.current = first !== undefined && element !== null ? { id: first.id, top: element.getBoundingClientRect().top } : null;

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
    const send = async (body: string, mentions: MentionPayloadRow[], image: File | null) => {
        pinned.current = true;
        await streamSend(body, mentions, image);
        setAtBottom(true);
    };

    const remove = async (id: number) => {
        if (await confirm({ title: t('Delete this message?'), confirmLabel: t('Delete'), danger: true })) {
            void stream.remove(id);
        }
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
                                <TalkMessageRow message={message} onDelete={remove} />
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
                sits one line above the composer, which stands on the same bottom offset. */}
            {!atBottom && (
                <div className="pointer-events-none sticky bottom-[calc(var(--modern-bottom-offset)+4.25rem)] z-20 flex h-0 items-end justify-center">
                    <Button size="sm" variant="secondary" onClick={jumpToLatest} className="pointer-events-auto shadow-md">
                        <ArrowDown className="size-4" aria-hidden />
                        {t('Jump to latest')}
                    </Button>
                </div>
            )}

            {canPost ? (
                <TalkComposer groupId={group.id} groupName={group.name} onSend={send} />
            ) : (
                <p className="text-sm text-muted-foreground">{t('Join this %community% to post.')}</p>
            )}
        </>
    );
}
