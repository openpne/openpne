import { ArrowUp, CheckCheck } from 'lucide-react';
import { Avatar } from '@/components/avatar';
import { Card } from '@/components/card';
import { Button } from '@/components/ui/button';
import { Heading } from '@/components/ui/heading';
import { jumpToUnreadPhrase } from '@/lib/count-phrase';
import { useT } from '@/lib/i18n';
import { useDateFormat } from '@/lib/use-date-format';
import { cn } from '@/lib/utils';
import type { TalkUnreadDigest } from './types';

/**
 * The catch-up card: what was said while the reader was away, and the one tap that spends it.
 *
 * Drawn at the boundary in both of the states that boundary can be in — at the separator when the
 * line is on the page, in the banner's place when it is above it — with the same payload and the
 * same actions either way, so returning to a room does not depend on where the boundary happens to
 * have fallen in the loaded slice.
 *
 * The summary is a slot rather than a sentence built inline: it is the one part of the card meant to
 * be replaced later by a written account of the backlog, and everything around it — the period, the
 * faces, the pictures, the two actions — stays as it is when that happens.
 */
export function TalkUnreadDigestCard({
    digest,
    onMarkAllRead,
    marking,
    onJump,
    className,
}: {
    digest: TalkUnreadDigest;
    onMarkAllRead: () => void;
    /** The catch-up is on the wire. The card stays until the page says the backlog is spent. */
    marking: boolean;
    /** Offered only where the card stands in for the banner: at the separator there is nowhere to go. */
    onJump?: () => void;
    className?: string;
}) {
    const t = useT();
    const { absolute } = useDateFormat();

    return (
        <Card className={cn('px-4 py-3 sm:px-5', className)}>
            {/* The card's title, and the slot a written account of the backlog would replace. */}
            <Heading as="h2" variant="minor">
                {t(':count messages while you were away', { count: digest.count })}
            </Heading>
            <p className="mt-0.5 text-xs text-muted-foreground">{t('Since :time', { time: absolute(digest.since) })}</p>

            {(digest.participants.length > 0 || digest.thumbnails.length > 0) && (
                <div className="mt-2 flex flex-wrap items-center gap-x-4 gap-y-2">
                    {digest.participants.length > 0 && (
                        // Spaced rather than overlapped: the card is drawn on two different surfaces
                        // (tinted at the separator, plain over the conversation), and a stack needs a
                        // ring in whichever one it happens to be lying on.
                        <ul className="flex items-center gap-1">
                            {digest.participants.map((member) => (
                                <li key={member.id}>
                                    <Avatar
                                        id={member.id}
                                        name={member.name}
                                        src={member.imageUrl}
                                        color={member.avatarColor}
                                        isAi={member.isAi}
                                        size="sm"
                                    />
                                </li>
                            ))}
                        </ul>
                    )}

                    {digest.thumbnails.length > 0 && (
                        <ul className="flex items-center gap-1">
                            {digest.thumbnails.map((image) => (
                                <li key={image.id}>
                                    {/* Decorative: a preview of the backlog the line above has already
                                        described, standing for nothing a reader has to act on. */}
                                    <img src={image.thumbnailUrl} alt="" width={40} height={40} className="size-10 rounded-md object-cover" />
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            )}

            <div className="mt-3 flex flex-wrap items-center gap-2">
                <Button size="sm" variant="secondary" loading={marking} onClick={onMarkAllRead}>
                    <CheckCheck className="size-4" aria-hidden />
                    {t('Mark all as read')}
                </Button>
                {onJump !== undefined && (
                    <Button size="sm" variant="ghost" onClick={onJump}>
                        <ArrowUp className="size-4" aria-hidden />
                        {jumpToUnreadPhrase(t, digest.count)}
                    </Button>
                )}
            </div>
        </Card>
    );
}
