import { Link } from '@inertiajs/react';
import { AiChip } from '@/components/ai-chip';
import { Avatar } from '@/components/avatar';
import { Timestamp } from '@/components/timestamp';
import { Heading } from '@/components/ui/heading';
import { Panel } from '@/components/ui/surface';
import { fitFallbackUrl, fitSrcSet } from '@/lib/image-sources';
import { useT } from '@/lib/i18n';
import { splitLead } from './lead';
import type { IssueStory } from './types';

/**
 * What the card is about to paint. The lead card takes the whole frame at every width and the cards
 * under it run two across from `sm`, so the frame's 42rem is the widest either is drawn — declared
 * for both, which over-declares the half-width ones by a rung. That is the direction to err in
 * (docs/internals/images.md).
 */
const CARD_HERO_SIZES = '(min-width: 40rem) 37.5rem, 92vw';

/** Where the story is read in full, and what it is called there. */
export function storyTarget(story: IssueStory): { href: string; headline: string } {
    switch (story.kind) {
        case 'diary':
            return { href: `/diary/${story.item.id}`, headline: story.item.title };
        case 'timeline':
            // A post has no title, so the line its author opened with stands in for one. Plain text
            // here, unlike the lead article: the headline is the card's link, and a mention drawn as
            // a link of its own inside it would nest two anchors.
            return { href: `/timeline/${story.item.id}`, headline: splitLead(story.item.body).lead };
        case 'topic':
            return { href: `/topics/${story.item.id}`, headline: story.item.name };
        case 'event':
            return { href: `/events/${story.item.id}`, headline: story.item.name };
    }
}

/**
 * A story as one card: picture, headline, byline, and as much of the body as four lines hold.
 *
 * The preview form, drawn where an issue has more than one thing worth leading with — the two or
 * three abreast, and the lead of an issue whose remainder is a list. A preview says what the story
 * is and hands the reader the way in; it does not restate the body, which is why no link card is
 * drawn here — a link card is a restatement of a link in a body, and this card has no body.
 */
export function StoryCard({ story }: { story: IssueStory }) {
    const t = useT();
    const { href, headline } = storyTarget(story);
    const { item } = story;
    const author = item.author;
    const hero = item.images[0];
    const group = story.kind === 'topic' || story.kind === 'event' ? story.item.group : null;
    const name = author?.name ?? t('Withdrawn member');

    return (
        <Panel className="h-full">
            <Heading as="h2" variant="group" className="break-words">
                <Link href={href} className="hover:underline">
                    {headline}
                </Link>
            </Heading>
            {hero && (
                // Decorative: the headline above it is the link's own text. The picture sits under
                // the headline rather than over it so that cards standing abreast — one with a
                // picture, one without — still open on the same line.
                <img
                    src={fitFallbackUrl(hero.fitSources) ?? ''}
                    srcSet={fitSrcSet(hero.fitSources, hero.width, hero.height) ?? undefined}
                    sizes={CARD_HERO_SIZES}
                    alt=""
                    loading="lazy"
                    className="mt-3 aspect-[4/3] w-full rounded-lg bg-muted object-cover"
                />
            )}
            <div className="mt-2 flex min-w-0 flex-wrap items-center gap-1.5 text-xs text-muted-foreground">
                <Avatar
                    id={author?.id ?? 0}
                    name={name}
                    src={author?.imageUrl ?? null}
                    color={author?.avatarColor ?? null}
                    isAi={author?.isAi ?? false}
                    size="sm"
                    decorative
                />
                <span className="truncate">{name}</span>
                <AiChip isAi={author?.isAi ?? false} />
                {group && <span className="truncate">{group.name}</span>}
                <Timestamp at={item.createdAt} preset="listStamp" />
            </div>
            {/* `break-words`, or an unbroken run — a bare URL — sets the paragraph's width from its
                own length and the clamp cuts it mid-word with no ellipsis to say so. */}
            {item.excerpt !== '' && <p className="mt-2 line-clamp-4 break-words text-sm text-muted-foreground">{item.excerpt}</p>}
        </Panel>
    );
}
