import { Link } from '@inertiajs/react';
import { MessageCircle } from 'lucide-react';
import { AiChip } from '@/components/ai-chip';
import { Avatar } from '@/components/avatar';
import { CountBadge } from '@/components/entry-row';
import type { GridImage } from '@/components/image-grid';
import { Timestamp } from '@/components/timestamp';
import { Heading } from '@/components/ui/heading';
import { stretchedLink } from '@/components/ui/surface';
import { commentsPhrase, repliesPhrase } from '@/lib/count-phrase';
import { useT } from '@/lib/i18n';
import { fitFallbackUrl, fitSrcSet } from '@/lib/image-sources';
import { cn } from '@/lib/utils';
import type { IssueStory } from './types';

/**
 * The content column is capped at 42rem by the member frame; a pair splits it in two at every width,
 * a lone second gives its picture a fixed 7rem beside the words, and a row a 5rem square.
 */
const LEAD_SIZES = 'min(42rem, 100vw)';
const PAIR_SIZES = 'min(21rem, 50vw)';
const SIDE_SIZES = '7rem';
const ROW_SIZES = '5rem';

/**
 * The fit ladder and `object-cover`, never the crop ladders: covering a server crop into another
 * shape would re-crop it (docs/internals/images.md, "The two ladders"). Decorative: the headline
 * beside it names the story.
 */
export function StoryPicture({ image, shape, sizes }: { image: GridImage; shape: string; sizes: string }) {
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

export function Dot() {
    return <span aria-hidden>·</span>;
}

/**
 * Plain text throughout, names included: the block around it is one link, and an anchor inside an
 * anchor resolves for neither a browser nor a screen reader.
 */
function Byline({ story }: { story: IssueStory }) {
    const t = useT();
    const author = story.author;
    const name = author?.name ?? t('%Withdrawn_member%');

    return (
        <div className="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground">
            <Avatar
                id={author?.id ?? 0}
                name={name}
                src={author?.imageUrl ?? null}
                color={author?.avatarColor ?? null}
                isAi={author?.isAi ?? false}
                size="xs"
                decorative
            />
            <span className="min-w-0 truncate">{name}</span>
            <AiChip isAi={author?.isAi ?? false} />
            {story.group && (
                <span className="flex min-w-0 items-center gap-x-1.5">
                    <Dot />
                    <span className="min-w-0 truncate">{story.group.name}</span>
                </span>
            )}
            <span className="flex items-center gap-x-1.5 whitespace-nowrap">
                <Dot />
                <Timestamp at={story.createdAt} preset="listStamp" />
            </span>
            {/* The icon separates the count, so a narrow column that wraps it leaves no dot behind. */}
            {story.commentCount > 0 && (
                <CountBadge
                    icon={MessageCircle}
                    count={story.commentCount}
                    srLabel={story.kind === 'timeline' ? repliesPhrase(t, story.commentCount) : commentsPhrase(t, story.commentCount)}
                />
            )}
        </div>
    );
}

function Dek({ text, className }: { text: string; className: string }) {
    return text === '' ? null : <p className={cn('break-words text-foreground', className)}>{text}</p>;
}

/** The block is the link (docs/internals/home-issues.md, "Rendering"), which is why the byline's names are text. */
export function LeadStory({ story }: { story: IssueStory }) {
    return (
        <div className="relative flex flex-col gap-2.5">
            {story.image && <StoryPicture image={story.image} shape="mb-1 aspect-video w-full" sizes={LEAD_SIZES} />}
            <Heading as="h2" variant="headline" face="serif" className="line-clamp-3">
                <Link href={story.href} className={stretchedLink}>
                    {story.headline}
                </Link>
            </Heading>
            <Dek text={story.dek} className={cn('text-base leading-relaxed', story.image ? 'line-clamp-3' : 'line-clamp-4')} />
            <Byline story={story} />
        </div>
    );
}

/** Two stories abreast at every width, a rule between them; the byline sits at the foot of each column. */
export function StoryPair({ stories }: { stories: [IssueStory, IssueStory] }) {
    return (
        <div className="grid grid-cols-2 border-t border-border pt-5">
            {stories.map((story, index) => (
                <div
                    key={`${story.kind}-${story.id}`}
                    className={cn('relative flex min-w-0 flex-col gap-1.5', index === 0 ? 'pr-3.5' : 'border-l border-border pl-3.5')}
                >
                    {story.image && <StoryPicture image={story.image} shape="mb-1 aspect-[4/3] w-full" sizes={PAIR_SIZES} />}
                    <Heading as="h2" variant="section" face="serif" className="line-clamp-3 break-words">
                        <Link href={story.href} className={stretchedLink}>
                            {story.headline}
                        </Link>
                    </Heading>
                    <Dek text={story.dek} className="line-clamp-3 text-sm" />
                    <div className="mt-auto pt-1">
                        <Byline story={story} />
                    </div>
                </div>
            ))}
        </div>
    );
}

/** A second story with no partner takes the full width, its picture beside the words rather than above. */
export function SideStory({ story }: { story: IssueStory }) {
    return (
        <div className="relative flex items-start gap-3.5 border-t border-border pt-5">
            <div className="flex min-w-0 flex-1 flex-col gap-1.5">
                <Heading as="h2" variant="group" face="serif" className="line-clamp-3 break-words">
                    <Link href={story.href} className={stretchedLink}>
                        {story.headline}
                    </Link>
                </Heading>
                <Dek text={story.dek} className="line-clamp-3 text-sm" />
                <Byline story={story} />
            </div>
            {story.image && <StoryPicture image={story.image} shape="aspect-[4/3] w-28 shrink-0" sizes={SIDE_SIZES} />}
        </div>
    );
}

/**
 * The headline is the row's content line and not a heading rank: size and color say which of the two
 * lines identifies it (docs/internals/typography.md, "Heading roles").
 */
export function StoryRow({ story }: { story: IssueStory }) {
    return (
        <li className="relative flex items-start gap-3 border-t border-border py-4">
            <div className="min-w-0 flex-1 space-y-1">
                <p className="line-clamp-2 break-words font-serif text-base text-foreground">
                    <Link href={story.href} className={stretchedLink}>
                        {story.headline}
                    </Link>
                </p>
                <Dek text={story.dek} className="line-clamp-1 text-sm text-muted-foreground" />
                <Byline story={story} />
            </div>
            {story.image && <StoryPicture image={story.image} shape="size-20 shrink-0" sizes={ROW_SIZES} />}
        </li>
    );
}
