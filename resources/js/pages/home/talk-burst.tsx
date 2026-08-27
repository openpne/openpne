import { Link } from '@inertiajs/react';
import { Avatar } from '@/components/avatar';
import { Card } from '@/components/card';
import { CommunityImage } from '@/components/community-image';
import { Heading } from '@/components/ui/heading';
import { useT } from '@/lib/i18n';
import { fitFallbackUrl, fitSrcSet } from '@/lib/image-sources';
import { useDateFormat } from '@/lib/use-date-format';
import { cn } from '@/lib/utils';
import type { TalkBurst } from './types';

/**
 * What the pictures are drawn at: a third of the card below the desktop frame, and about 13rem in
 * the three-across cell at its widest (docs/internals/images.md).
 */
const PICTURE_SIZES = '(min-width: 64rem) 13rem, 30vw';

/** How many pictures a glimpse is. Past three the card stops being a glimpse and becomes an album. */
const PICTURES = 3;

/**
 * A run of talk in one group, as the front page reports it: whose room it is, how much was said,
 * since when, by whom, and a glimpse of what was posted.
 *
 * The pictures carry the card, because what a run of talk looked like is the thing a reader can
 * judge from outside the room — a count alone says a number happened. They are drawn at the size a
 * picture is worth looking at rather than as markers beside the faces, which is what the row of
 * 40px squares had made them.
 *
 * Not the catch-up card (group/talk/unread-digest.tsx), though it reads like one: that card requires
 * the two actions that spend a backlog, and this has no backlog to spend. Its one way out is the
 * room itself.
 */
export function TalkBurstCard({ burst }: { burst: TalkBurst }) {
    const t = useT();
    const { listStamp } = useDateFormat();
    const pictures = burst.thumbnails.slice(0, PICTURES);

    return (
        <Card className="space-y-3 px-4 py-3 sm:px-5">
            <div className="flex min-w-0 items-center gap-2">
                <CommunityImage name={burst.group.name} src={burst.group.imageUrl} className="size-10" textClassName="text-sm" decorative />
                <Link href={burst.href} className="min-w-0 truncate text-sm text-link hover:underline">
                    {burst.group.name}
                </Link>
            </div>

            <Heading as="h2" variant="group">
                {t(':count messages', { count: burst.count })}
            </Heading>

            <div>
                {burst.participants.length > 0 && (
                    // Spaced rather than overlapped, as on the catch-up card: a stack needs a ring in
                    // whichever surface it happens to be lying on.
                    <ul className="mb-1 flex flex-wrap items-center gap-2" aria-label={t('Participants')}>
                        {burst.participants.map((member) => (
                            <li key={member.id}>
                                <Avatar
                                    id={member.id}
                                    name={member.name}
                                    src={member.imageUrl}
                                    color={member.avatarColor}
                                    isAi={member.isAi}
                                    size="md"
                                />
                            </li>
                        ))}
                    </ul>
                )}
                <p className="text-xs text-muted-foreground">{t('Since :time', { time: listStamp(burst.since) })}</p>
            </div>

            {pictures.length > 0 && (
                <div className={cn('grid gap-1', pictures.length === 2 && 'grid-cols-2', pictures.length >= PICTURES && 'grid-cols-3')}>
                    {pictures.map((image) => (
                        <div
                            key={image.id}
                            className={cn('overflow-hidden rounded-lg bg-muted', pictures.length === 1 ? 'aspect-video' : 'aspect-square')}
                        >
                            {/* Decorative: the headline above has already counted what these are. A
                                square box crops rather than fits, which the fit ladder still serves —
                                the `w` descriptors are the candidates' own widths, not the box's. */}
                            <img
                                src={fitFallbackUrl(image.fitSources) ?? image.thumbnailUrl}
                                srcSet={fitSrcSet(image.fitSources, image.width, image.height) ?? undefined}
                                sizes={PICTURE_SIZES}
                                alt=""
                                loading="lazy"
                                className="size-full object-cover"
                            />
                        </div>
                    ))}
                </div>
            )}

            <div className="text-sm">
                <Link href={burst.href} className="text-link hover:underline">
                    {t('Open talk')}
                </Link>
            </div>
        </Card>
    );
}
