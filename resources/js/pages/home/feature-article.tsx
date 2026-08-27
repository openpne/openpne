import { Link } from '@inertiajs/react';
import { MessageCircle, Users } from 'lucide-react';
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
import { storyTarget } from './story-card';
import type { BoardScope, IssueStory } from './types';

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
 * The whole story, drawn in full: the form an issue takes when it has exactly one thing in it.
 *
 * This is the only place in an issue a body is printed rather than previewed, and therefore the only
 * place a link card is drawn (docs/internals/link-cards.md — a screen that draws a full body draws
 * its card). The cards and briefs around it are previews, and a preview restating a link the reader
 * has not been shown yet would be a claim about a page with nothing to check it against.
 */
export function FeatureArticle({ story }: { story: IssueStory }) {
    const t = useT();
    const { href, headline } = storyTarget(story);
    // A post's headline is the line its author opened with, and a mention in that line stays the
    // link it is everywhere else — so the headline cannot itself be one. Anchors do not nest, and
    // the way into the post is the "continue reading" link at the foot of the article.
    const lead = story.kind === 'timeline' ? splitLead(story.item.body, story.item.mentions, story.item.tags) : null;

    return (
        <Panel>
            <article>
                <Heading as="h2" variant="display" className="mb-3">
                    {lead ? (
                        <EntityText text={lead.lead} mentions={lead.leadMentions} tags={lead.leadTags} />
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

                <ImageGrid images={story.item.images} variant="post" className="mt-3" />

                <div className="mt-3">
                    <StoryBody story={story} />
                </div>

                <LinkCard card={story.item.linkCard} className="mt-3" />

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
                    <Link href={href} className="text-link hover:underline">
                        {t('Continue reading')}
                    </Link>
                </div>
            </article>
        </Panel>
    );
}
