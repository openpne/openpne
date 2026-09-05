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
 * The content column is capped at 42rem by the member frame, so the lead runs the column's width and
 * a second half of it from `sm` up, where the pair splits into two.
 */
const LEAD_SIZES = 'min(42rem, 100vw)';
const SECOND_SIZES = '(min-width: 40rem) 21rem, 100vw';
const THUMB_SIZES = '6rem';

/**
 * The fit ladder and `object-cover`, never the crop ladders: covering a server crop into another
 * shape would re-crop it (docs/internals/images.md, "The two ladders"). Decorative: the headline
 * beside it names the story.
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
 * Plain text throughout, names included: the block around it is one link, and an anchor inside an
 * anchor resolves for neither a browser nor a screen reader.
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
 * The card is the link (docs/internals/home-issues.md, "Rendering"), which is why the byline's names
 * are text.
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
 * The headline is the row's content line and not a heading rank: size and color say which of the two
 * lines identifies it (docs/internals/typography.md, "Heading roles").
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
