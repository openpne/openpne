import { Head, usePage } from '@inertiajs/react';
import { useEffect, useLayoutEffect, useRef } from 'react';
import { useConfirm } from '@/components/confirm-dialog';
import { Button } from '@/components/ui/button';
import { List, Panel } from '@/components/ui/surface';
import { useT } from '@/lib/i18n';
import type { CommunitySummary } from '@/pages/community/types';
import type { PageProps } from '@/types';
import { TalkComposer } from './composer';
import { TalkMessageRow } from './message-row';
import type { TalkPage } from './types';
import { useTalkStream } from './use-talk-stream';

interface TalkProps extends PageProps {
    group: CommunitySummary;
    page: TalkPage;
    canPost: boolean;
}

/** How close to the foot still counts as reading the newest message. */
const NEAR_BOTTOM_PX = 96;

/** One talk list per page, so the message's own id names its row without a container ref. */
const messageElement = (id: number): Element | null => document.querySelector(`[data-talk-message-id="${id}"]`);

export default function GroupTalkIndex() {
    const t = useT();
    const confirm = useConfirm();
    const { group, page, canPost } = usePage<TalkProps>().props;
    const stream = useTalkStream(group.id, page);
    const messages = stream.messages;
    const streamSend = stream.send;

    // Whether the reader is at the newest message. A conversation that scrolls itself while someone
    // is reading back through it has taken the page away from them.
    const pinned = useRef(true);
    // The message "load older" was standing on, and where it sat in the viewport.
    const anchor = useRef<{ id: number; top: number } | null>(null);

    useEffect(() => {
        const onScroll = () => {
            pinned.current =
                window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - NEAR_BOTTOM_PX;
        };
        window.addEventListener('scroll', onScroll, { passive: true });

        return () => window.removeEventListener('scroll', onScroll);
    }, []);

    // Open on the newest message, the way a conversation is read.
    useLayoutEffect(() => {
        window.scrollTo({ top: document.documentElement.scrollHeight });
    }, []);

    useLayoutEffect(() => {
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

        if (pinned.current) {
            window.scrollTo({ top: document.documentElement.scrollHeight });
        }
    }, [messages]);

    const loadOlder = () => {
        const first = messages[0];
        const element = first === undefined ? null : messageElement(first.id);
        anchor.current = first !== undefined && element !== null ? { id: first.id, top: element.getBoundingClientRect().top } : null;

        void stream.loadOlder();
    };

    // Your own send always lands you on your own words. The pinned gate protects someone reading
    // back through history from being yanked by *others'* arrivals; writing is the opposite intent.
    const send = async (body: string) => {
        await streamSend(body);
        pinned.current = true;
    };

    const remove = async (id: number) => {
        if (await confirm({ title: t('Delete this message?'), confirmLabel: t('Delete'), danger: true })) {
            void stream.remove(id);
        }
    };

    return (
        <>
            <Head title={t('Talk')} />

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
                            <TalkMessageRow key={message.id} message={message} onDelete={remove} />
                        ))}
                    </List>
                )}
            </Panel>

            {canPost ? (
                <TalkComposer onSend={send} />
            ) : (
                <p className="text-sm text-muted-foreground">{t('Join this %community% to post.')}</p>
            )}
        </>
    );
}
