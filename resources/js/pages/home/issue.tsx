import { Head, usePage } from '@inertiajs/react';
import { List, Panel } from '@/components/ui/surface';
import { useT } from '@/lib/i18n';
import { useDateFormat } from '@/lib/use-date-format';
import { cn } from '@/lib/utils';
import { GroupGrid } from '../unified/group-grid';
import { PeopleGrid } from '../unified/people-grid';
import { Colophon } from './colophon';
import { UpcomingEventRow } from './event-row';
import { IssueNav } from './issue-nav';
import { Masthead } from './masthead';
import { LeadStory, SecondStory, StoryRow } from './story';
import { TalkBurstCard } from './talk-burst';
import type { IssuePageProps, IssueStory } from './types';
import { WelcomePanel } from './welcome';

const storyKey = (story: IssueStory): string => `${story.kind}-${story.id}`;

const SECONDS = 2;

/**
 * Rank alone decides the placement (docs/internals/home-issues.md, "Rendering"). A lone second takes
 * the full width instead of half of it.
 */
function Stories({ stories }: { stories: IssueStory[] }) {
    const [lead, ...rest] = stories;
    const seconds = rest.slice(0, SECONDS);
    const rows = rest.slice(SECONDS);

    // A type guard, not an empty state: the band is absent from the payload when it has nothing in
    // it, so `stories` is never `[]` and there is always a lead to open with.
    if (lead === undefined) {
        return null;
    }

    return (
        <>
            <LeadStory story={lead} />

            {seconds.length > 0 && (
                <div className={cn('grid gap-4', seconds.length > 1 && 'sm:grid-cols-2')}>
                    {seconds.map((story) => (
                        <SecondStory key={storyKey(story)} story={story} />
                    ))}
                </div>
            )}

            {rows.length > 0 && (
                <Panel flush>
                    <List>
                        {rows.map((story) => (
                            <StoryRow key={storyKey(story)} story={story} />
                        ))}
                    </List>
                </Panel>
            )}
        </>
    );
}

/**
 * One issue (号), the same for every member, serving `/` and a dated URL through `home/archive`.
 * Every optional section is drawn exactly when its key is present
 * (docs/internals/home-issues.md, "Rendering").
 */
export default function HomeIssue() {
    const t = useT();
    const { issue, prev, next, auth, enabledFeatures } = usePage<IssuePageProps>().props;
    const date = useDateFormat();

    if (issue === null) {
        const today = date.siteDay(new Date().toISOString()) ?? '';

        return (
            <>
                <Head title={t('What happened')} />
                {/* Today's dateline over nothing: the site still has a front page, it just has
                    nothing on it yet. */}
                <Masthead from={today} to={today} />
                {auth.user && <WelcomePanel name={auth.user.name} enabledFeatures={enabledFeatures} />}
                <p className="text-sm text-muted-foreground">{t('The first post will appear here the next morning.')}</p>
                {/* Nothing has been put together, so there is no instant to stamp it with. */}
                <Colophon window={null} stale={false} />
            </>
        );
    }

    return (
        <>
            <Head title={t('What happened')} />
            <Masthead from={issue.days.from} to={issue.days.to} />

            {issue.stories && <Stories stories={issue.stories} />}

            {issue.talkBursts?.map((burst) => <TalkBurstCard key={burst.group.id} burst={burst} />)}

            {issue.newcomers && (
                <Panel title={t('New members')}>
                    <PeopleGrid people={issue.newcomers} />
                </Panel>
            )}

            {issue.newGroups && (
                <Panel title={t('New %communities%')}>
                    <GroupGrid groups={issue.newGroups} />
                </Panel>
            )}

            {issue.upcomingEvents && (
                <Panel flush title={t('Upcoming events')}>
                    <List>
                        {issue.upcomingEvents.map((event) => (
                            <UpcomingEventRow key={event.id} event={event} />
                        ))}
                    </List>
                </Panel>
            )}

            <IssueNav prev={prev} next={next} />
            {/* Only the freshest page can be "nothing new yet": an archived day has a day after it. */}
            <Colophon window={issue.window} stale={next === null && !issue.isCurrent} />
        </>
    );
}
