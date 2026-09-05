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

/** See docs/internals/group-talk.md, "The absence digest". */
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
            {/* Plural only: the card exists from TalkAbsenceDigest::THRESHOLD messages up, never at
                one. */}
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
