import { Link } from '@inertiajs/react';
import { MessageCircle } from 'lucide-react';
import { AiChip } from '@/components/ai-chip';
import { Avatar } from '@/components/avatar';
import { Card } from '@/components/card';
import { CountBadge } from '@/components/entry-row';
import type { GridImage } from '@/components/image-grid';
import { Timestamp } from '@/components/timestamp';
import { Heading } from '@/components/ui/heading';
import { ListRow, stretchedLink } from '@/components/ui/surface';
import { useT } from '@/lib/i18n';
import { fitFallbackUrl, fitSrcSet } from '@/lib/image-sources';
import { cn } from '@/lib/utils';
import type { IssueStory } from './types';

/** The card body's inset, kept level with a Panel's so a story sits like every other surface. */
const INSET = 'space-y-2 px-4 py-4 sm:px-5';

/**
 * What each placement paints, for the browser's candidate pick (docs/internals/images.md).
 *
 * The content column is capped at `max-w-2xl` (42rem) by the member frame, so the lead runs the
 * column's width and a second runs half of it from `sm` up, where the pair splits into two.
 */
const LEAD_SIZES = 'min(42rem, 100vw)';
const SECOND_SIZES = '(min-width: 40rem) 21rem, 100vw';
const THUMB_SIZES = '6rem';

/**
 * A story's picture, in whatever shape the placement asked for.
 *
 * The fit ladder and `object-cover`, never the crop ladders: those are cut at 3:4 and 3:2 and top out
 * at 600px, and neither is a shape here — covering a 3:2 source into a 16:9 hero would re-crop it,
 * which is the one thing docs/internals/images.md says CSS may not do to a server crop. The fit
 * candidates are the picture's own widths up to 1200, so the descriptors stay true whatever the box.
 *
 * Decorative: the headline beside it names the story, and a square of a photograph is not a thing to
 * name twice.
 */
function StoryPicture({ image, shape, sizes }: { image: GridImage; shape: string; sizes: string }) {
    return (
        <img
            src={fitFallbackUrl(image.fitSources) ?? ''}
            srcSet={fitSrcSet(image.fitSources, image.width, image.height) ?? undefined}
            sizes={sizes}
            alt=""
            loading="lazy"
            className={cn('bg-muted object-cover', shape)}
        />
    );
}

function Dot() {
    return <span aria-hidden>·</span>;
}

/**
 * Who wrote it, where, when, and how much was said back.
 *
 * Plain text throughout, including the names: the block around it is one link, and an anchor inside
 * an anchor is not something a browser or a screen reader can resolve. A name here names; the way to
 * the person who owns it is their story's page.
 */
function Byline({ story }: { story: IssueStory }) {
    const t = useT();
    const author = story.author;
    const name = author?.name ?? t('Withdrawn member');

    return (
        <div className="flex min-w-0 flex-wrap items-center gap-1.5 text-xs text-muted-foreground">
            <Avatar
                id={author?.id ?? 0}
                name={name}
                src={author?.imageUrl ?? null}
                color={author?.avatarColor ?? null}
                isAi={author?.isAi ?? false}
                size="sm"
                decorative
            />
            <span className="min-w-0 truncate text-foreground">{name}</span>
            <AiChip isAi={author?.isAi ?? false} />
            {story.group && (
                <>
                    <Dot />
                    <span className="min-w-0 truncate">{story.group.name}</span>
                </>
            )}
            <Dot />
            <Timestamp at={story.createdAt} preset="listStamp" />
            {story.commentCount > 0 && (
                <>
                    <Dot />
                    <CountBadge
                        icon={MessageCircle}
                        count={story.commentCount}
                        srLabel={
                            story.kind === 'timeline'
                                ? t(':count replies', { count: story.commentCount })
                                : t(':count comments', { count: story.commentCount })
                        }
                    />
                </>
            )}
        </div>
    );
}

/**
 * The story the issue opens with: the widest headline on the page, over the biggest picture.
 *
 * The card is the link. The anchor covers it from the headline outward (`stretchedLink`), so the
 * whole block is one target named by the one thing that says what it is — and there is nothing else
 * inside it to click, which is why the byline's names are text.
 *
 * With no picture the headline keeps its rank and the dek runs a line longer: the lead's weight comes
 * from placement and size first, and a hero-shaped gap where no photograph exists would be an
 * announcement that one is missing.
 */
export function LeadStory({ story }: { story: IssueStory }) {
    return (
        <Card className="relative">
            {story.image && <StoryPicture image={story.image} shape="aspect-video w-full" sizes={LEAD_SIZES} />}
            <div className={INSET}>
                <Heading as="h2" variant="display" className="line-clamp-3">
                    <Link href={story.href} className={stretchedLink}>
                        {story.headline}
                    </Link>
                </Heading>
                {story.dek !== '' && <p className={cn('text-base text-foreground', story.image ? 'line-clamp-3' : 'line-clamp-4')}>{story.dek}</p>}
                <Byline story={story} />
            </div>
        </Card>
    );
}

/** Rank 2 and 3, side by side from `sm`: the same card a rank down, at a squarer picture. */
export function SecondStory({ story }: { story: IssueStory }) {
    return (
        // A column, so two cards standing abreast keep their bylines on one line however their
        // headlines wrap: the byline is pushed to the foot of the card, not left where the text ends.
        <Card className="relative flex flex-col">
            {story.image && <StoryPicture image={story.image} shape="aspect-[4/3] w-full" sizes={SECOND_SIZES} />}
            <div className={cn(INSET, 'flex flex-1 flex-col')}>
                <Heading as="h2" variant="group" className="line-clamp-2 break-words">
                    <Link href={story.href} className={stretchedLink}>
                        {story.headline}
                    </Link>
                </Heading>
                {story.dek !== '' && (
                    <p className={cn('text-sm text-muted-foreground', story.image ? 'line-clamp-2' : 'line-clamp-3')}>{story.dek}</p>
                )}
                <div className="mt-auto">
                    <Byline story={story} />
                </div>
            </div>
        </Card>
    );
}

/**
 * Rank 4 and below: a row, with the picture as a square beside the words rather than over them.
 *
 * The headline is the row's content line and not a heading rank — the size and the color say which
 * of the two lines identifies it, as they do in every list on the site (docs/internals/typography.md).
 */
export function StoryRow({ story }: { story: IssueStory }) {
    return (
        <ListRow rowLink className="items-start">
            <div className="min-w-0 flex-1 space-y-0.5">
                <p className="line-clamp-2 text-base text-foreground">
                    <Link href={story.href} className={stretchedLink}>
                        {story.headline}
                    </Link>
                </p>
                {story.dek !== '' && <p className="line-clamp-1 text-sm text-muted-foreground">{story.dek}</p>}
                <Byline story={story} />
            </div>
            {story.image && <StoryPicture image={story.image} shape="size-24 shrink-0 rounded-lg" sizes={THUMB_SIZES} />}
        </ListRow>
    );
}
