import { Head, usePage } from '@inertiajs/react';
import { List, Panel } from '@/components/ui/surface';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { GroupGrid } from '@/components/sections/group-grid';
import { PeopleGrid } from '@/components/sections/people-grid';
import { Colophon } from './colophon';
import { UpcomingEventRow } from './event-row';
import { IssueNav } from './issue-nav';
import { Masthead } from './masthead';
import { SiteNotice } from './site-notice';
import { LeadStory, SecondStory, StoryRow } from './story';
import { TalkBurstCard } from './talk-burst';
import type { IssuePageProps, IssueStory } from './types';
import { WelcomePanel } from './welcome';

const storyKey = (story: IssueStory): string => `${story.kind}-${story.id}`;

/** How many stories are cards beside the lead, not a duration. */
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
                        <SecondStory key={storyKey(story)} story={story} alone={seconds.length === 1} />
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
 * One issue, the same for every member, serving `/` and a dated URL through `home/archive`.
 * Every optional section is drawn exactly when its key is present
 * (docs/internals/home-issues.md, "Rendering").
 */
export default function HomeIssue() {
    const t = useT();
    const { issue, prev, next, auth, enabledFeatures, notice } = usePage<IssuePageProps>().props;

    if (issue === null) {
        return (
            <>
                <Head title={t('What happened')} />
                <SiteNotice notice={notice} />
                <Masthead days={null} prev={null} next={null} />
                {auth.user && <WelcomePanel name={auth.user.name} enabledFeatures={enabledFeatures} />}
                <p className="text-sm text-muted-foreground">{t('The first post will appear here the next morning.')}</p>
            </>
        );
    }

    return (
        <>
            <Head title={t('What happened')} />
            <SiteNotice notice={notice} />
            <Masthead days={issue.bornDays} prev={prev} next={next} />

            {issue.stories && <Stories stories={issue.stories} />}

            {issue.talkBursts?.map((burst) => <TalkBurstCard key={burst.group.id} burst={burst} />)}

            {issue.upcomingEvents && (
                <Panel flush title={t('Upcoming events')}>
                    <List>
                        {issue.upcomingEvents.map((event) => (
                            <UpcomingEventRow key={event.id} event={event} />
                        ))}
                    </List>
                </Panel>
            )}

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

            <IssueNav prev={prev} next={next} monthHref={issue.monthHref} />
            <Colophon window={issue.window} />
        </>
    );
}
