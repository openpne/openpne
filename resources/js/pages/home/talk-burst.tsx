import { Link } from '@inertiajs/react';
import { Avatar } from '@/components/avatar';
import { Card } from '@/components/card';
import { Heading } from '@/components/ui/heading';
import { useDateFormat } from '@/lib/use-date-format';
import { useT } from '@/lib/i18n';
import type { TalkBurst } from './types';

/**
 * A run of talk in one group, as the issue reports it: how much was said, since when, by whom, and a
 * glimpse of what was posted.
 *
 * The catch-up card's shape (group/talk/unread-digest.tsx), deliberately: a member who has seen one
 * reads this without learning anything new. It is not that component, though — the catch-up card
 * requires the two actions that spend a backlog, and an issue has no backlog to spend. Its one way
 * out is the room itself.
 */
export function TalkBurstCard({ burst }: { burst: TalkBurst }) {
    const t = useT();
    const { listStamp } = useDateFormat();

    return (
        <Card className="px-4 py-3 sm:px-5">
            <Heading as="h2" variant="minor">
                {t(':count messages in :name', { count: burst.count, name: burst.group.name })}
            </Heading>
            <p className="mt-0.5 text-xs text-muted-foreground">{t('Since :time', { time: listStamp(burst.since) })}</p>

            {(burst.participants.length > 0 || burst.thumbnails.length > 0) && (
                <div className="mt-2 flex flex-wrap items-center gap-x-4 gap-y-2">
                    {burst.participants.length > 0 && (
                        // Spaced rather than overlapped, as on the catch-up card: a stack needs a ring
                        // in whichever surface it happens to be lying on.
                        <ul className="flex items-center gap-1" aria-label={t('Participants')}>
                            {burst.participants.map((member) => (
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

                    {burst.thumbnails.length > 0 && (
                        <ul className="flex items-center gap-1">
                            {burst.thumbnails.map((image) => (
                                <li key={image.id}>
                                    {/* Decorative: a glimpse of what the line above has already counted. */}
                                    <img src={image.thumbnailUrl} alt="" width={40} height={40} className="size-10 rounded-md object-cover" />
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            )}

            <div className="mt-3 text-sm">
                <Link href={burst.href} className="text-link hover:underline">
                    {t('Open talk')}
                </Link>
            </div>
        </Card>
    );
}
