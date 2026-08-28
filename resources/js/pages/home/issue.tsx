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

/** How many stories are cards beside the lead before the rest become rows. */
const SECONDS = 2;

/**
 * The day's stories, ranked by how much room each gets rather than by how much of it is printed.
 *
 * Three placements, and the rank alone decides which: the lead over the width of the page, a pair of
 * cards under it, then rows. No band between them announces the change — a reader who has ever seen a
 * front page reads size and position as the ranking, and a heading saying "more stories" would be the
 * page explaining its own layout.
 *
 * A lone second takes the full width instead of half of it: two columns with one card in them is a
 * gap where the eye looks for the other story.
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
 * The site's front page: one issue (号), published once a day and the same for every member.
 *
 * Serves two screens. At `/` it is whatever the latest issue is; at a dated URL it is that day's,
 * through `home/archive`, which is this component under another name so the chrome can differ (an
 * archived issue crumbs back to the run; the current one is the hub top). Nothing else about them
 * differs, which is the point: a member linking someone a day's front page hands them the page they
 * read, not a rendering of it.
 *
 * **Every story is a way in.** The rank decides how much room it gets — the lead across the page, a
 * pair of cards, then rows — and each block is one link to the page the story is actually read on.
 * Nothing here prints a body: a front page is where a reader chooses, not where they read.
 *
 * Every optional section is drawn exactly when its key is present. Nothing counts to zero and says
 * so — an empty section is a section the issue does not have.
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
