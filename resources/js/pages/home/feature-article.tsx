import { Link } from '@inertiajs/react';
import { MessageCircle, Users } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { AiChip } from '@/components/ai-chip';
import { Avatar } from '@/components/avatar';
import { CommunityImage } from '@/components/community-image';
import { CountBadge } from '@/components/entry-row';
import { EntityText } from '@/components/entity-text';
import { ImageGrid } from '@/components/image-grid';
import { LinkCard } from '@/components/link-card';
import { RichBody } from '@/components/rich-body';
import { CivilDate, Timestamp } from '@/components/timestamp';
import { Heading } from '@/components/ui/heading';
import { Panel } from '@/components/ui/surface';
import { useT } from '@/lib/i18n';
import { splitLead } from './lead';
import { PictureStrip } from './picture-strip';
import type { BoardScope, IssueStory } from './types';

/** About eight lines of body, past which a story below the lead is cut off and offers its page. */
const CLAMP = 'relative max-h-[12rem] overflow-hidden';

/** Where the story is read in full, and what it is called there. */
export function storyTarget(story: IssueStory): { href: string; headline: string } {
    switch (story.kind) {
        case 'diary':
            return { href: `/diary/${story.item.id}`, headline: story.item.title };
        case 'timeline':
            // A post has no title, so the line its author opened with stands in for one.
            return { href: `/timeline/${story.item.id}`, headline: splitLead(story.item.body).lead };
        case 'topic':
            return { href: `/topics/${story.item.id}`, headline: story.item.name };
        case 'event':
            return { href: `/events/${story.item.id}`, headline: story.item.name };
    }
}

/** Who wrote it, when, and — on a board entry — where. */
function Byline({ story }: { story: IssueStory }) {
    const t = useT();
    const author = story.item.author;
    const group: BoardScope | null = story.kind === 'topic' || story.kind === 'event' ? story.item.group : null;
    const name = author?.name ?? t('Withdrawn member');

    return (
        <div className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
            <Avatar
                id={author?.id ?? 0}
                name={name}
                src={author?.imageUrl ?? null}
                color={author?.avatarColor ?? null}
                isAi={author?.isAi ?? false}
                size="md"
                decorative
            />
            {author ? (
                <Link href={`/member/${author.id}`} className="text-link hover:underline">
                    {name}
                </Link>
            ) : (
                <span>{name}</span>
            )}
            <AiChip isAi={author?.isAi ?? false} />
            {group && (
                <>
                    <CommunityImage name={group.name} src={group.imageUrl} className="size-5" textClassName="text-[10px]" decorative />
                    <Link href={`/groups/${group.id}`} className="text-link hover:underline">
                        {group.name}
                    </Link>
                </>
            )}
            <span>
                &mdash; <Timestamp at={story.item.createdAt} preset="absolute" />
            </span>
        </div>
    );
}

/**
 * The story's words. A record body goes through {@link RichBody}, which is the only path that may
 * inject server-rendered HTML; a post is plain text whose first line has already been spent on the
 * headline, so what is left is drawn with its entities and nothing at all when the post was one line.
 */
function StoryBody({ story }: { story: IssueStory }) {
    if (story.kind === 'timeline') {
        const { rest, restMentions, restTags } = splitLead(story.item.body, story.item.mentions, story.item.tags);

        if (rest === '') {
            return null;
        }

        return (
            <p className="whitespace-pre-wrap break-words">
                <EntityText text={rest} mentions={restMentions} tags={restTags} />
            </p>
        );
    }

    return <RichBody body={story.item.body} bodyHtml={story.item.bodyHtml} />;
}

/**
 * A body cut off at the clamp, with the cut said rather than implied: the last line fades out under
 * a scrim so the reader can see there is more, instead of meeting a sentence that stops mid-word.
 *
 * The fade is drawn only when there IS more — measured after layout, because nothing before it knows
 * how many lines a body takes. It starts on: a server render has no measurement to go by, and a fade
 * over a body that turned out to fit is a smaller lie than a hard cut over one that did not.
 *
 * The scrim is one arbitrary gradient over the card's own token, so it fades into whatever the
 * surface is painted in and stays right in both themes.
 */
function ClampedBody({ children }: { children: React.ReactNode }) {
    const body = useRef<HTMLDivElement>(null);
    const [clipped, setClipped] = useState(true);

    useEffect(() => {
        const el = body.current;

        if (el !== null) {
            setClipped(el.scrollHeight > el.clientHeight);
        }
    }, []);

    return (
        <div ref={body} className={CLAMP}>
            {children}
            {clipped && (
                <span aria-hidden className="pointer-events-none absolute inset-x-0 bottom-0 block h-10 bg-linear-to-t from-card to-transparent" />
            )}
        </div>
    );
}

/**
 * One story as an article: headline, byline, pictures, words.
 *
 * `lead` is rank 1 — the story the issue opens with. It is printed whole, at display rank, with its
 * pictures above the words and its link card under them: this is the only place in an issue a body
 * is drawn in full, and therefore the only place a card is drawn (docs/internals/link-cards.md — a
 * screen that draws a full body draws its card; a preview restating a link the reader has not been
 * shown would be a claim with nothing to check it against).
 *
 * Every other rank is the same article, smaller: a heading rank down, its pictures as a strip beside
 * the words rather than over them, and its body cut off at {@link ClampedBody} with the way in
 * underneath. The page is still for reading — the difference is how much of each story it prints,
 * not whether it prints one.
 */
export function FeatureArticle({ story, lead }: { story: IssueStory; lead: boolean }) {
    const t = useT();
    const { href, headline } = storyTarget(story);
    // A post's headline is the line its author opened with, and a mention in that line stays the
    // link it is everywhere else — so the headline cannot itself be one. Anchors do not nest.
    const opening = story.kind === 'timeline' ? splitLead(story.item.body, story.item.mentions, story.item.tags) : null;
    const body = <StoryBody story={story} />;

    return (
        <Panel>
            <article>
                <Heading as="h2" variant={lead ? 'display' : 'group'} className="mb-3 break-words">
                    {opening ? (
                        <EntityText text={opening.lead} mentions={opening.leadMentions} tags={opening.leadTags} />
                    ) : (
                        <Link href={href} className="hover:underline">
                            {headline}
                        </Link>
                    )}
                </Heading>

                <Byline story={story} />

                {story.kind === 'event' && (
                    <div className="mt-2 flex flex-wrap items-center gap-3 text-sm text-foreground">
                        <CivilDate value={story.item.openDate} weekday />
                        {story.item.area !== '' && <span>{story.item.area}</span>}
                    </div>
                )}

                {lead ? (
                    <ImageGrid images={story.item.images} variant="post" className="mt-3" />
                ) : (
                    <PictureStrip images={story.item.images} className="mt-3" />
                )}

                <div className="mt-3">{lead ? body : <ClampedBody>{body}</ClampedBody>}</div>

                {lead && <LinkCard card={story.item.linkCard} className="mt-3" />}

                <div className="mt-3 flex flex-wrap items-center gap-3 text-xs text-muted-foreground">
                    {story.kind === 'timeline' && (
                        <CountBadge
                            icon={MessageCircle}
                            count={story.item.replyCount}
                            srLabel={t(':count replies', { count: story.item.replyCount })}
                        />
                    )}
                    {story.kind === 'diary' && (
                        <CountBadge
                            icon={MessageCircle}
                            count={story.item.commentCount}
                            srLabel={t(':count comments', { count: story.item.commentCount })}
                        />
                    )}
                    {story.kind === 'event' && (
                        <CountBadge
                            icon={Users}
                            count={story.item.participantCount}
                            srLabel={t(':count participants', { count: story.item.participantCount })}
                        />
                    )}
                    {/* The lead is printed whole, so it has no reading to continue — except a post's,
                        whose headline could not be made a link and would otherwise leave the article
                        with no way to its own page at all. */}
                    {(!lead || opening) && (
                        <Link href={href} className="text-link hover:underline">
                            {t('Continue reading')}
                        </Link>
                    )}
                </div>
            </article>
        </Panel>
    );
}
