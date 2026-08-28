import { Head, usePage } from '@inertiajs/react';
import { List, Panel } from '@/components/ui/surface';
import { useT } from '@/lib/i18n';
import { useDateFormat } from '@/lib/use-date-format';
import { GroupGrid } from '../unified/group-grid';
import { PeopleGrid } from '../unified/people-grid';
import { Colophon } from './colophon';
import { UpcomingEventRow } from './event-row';
import { FeatureArticle } from './feature-article';
import { IssueNav } from './issue-nav';
import { Masthead } from './masthead';
import { TalkBurstCard } from './talk-burst';
import type { IssuePageProps, IssueStory } from './types';
import { WelcomePanel } from './welcome';

const storyKey = (story: IssueStory): string => `${story.kind}-${story.item.id}`;

/**
 * The site's front page: one issue (号), published once a day and the same for every member.
 *
 * Serves two screens. At `/` it is whatever the latest issue is; at a dated URL it is that day's,
 * through `home/archive`, which is this component under another name so the chrome can differ (an
 * archived issue crumbs back to the run; the current one is the hub top). Nothing else about them
 * differs, which is the point: a member linking someone a day's front page hands them the page they
 * read, not a rendering of it.
 *
 * **Every story is an article.** The rank decides how much of it is printed — the lead whole, the
 * rest cut off at a clamp — and nothing here is a row or a card standing in for something to read.
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
                <Colophon publishedAt={null} window={null} stale={false} />
            </>
        );
    }

    return (
        <>
            <Head title={t('What happened')} />
            <Masthead from={issue.days.from} to={issue.days.to} />

            {issue.stories?.map((story, rank) => <FeatureArticle key={storyKey(story)} story={story} lead={rank === 0} />)}

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
            <Colophon publishedAt={issue.publishedAt} window={issue.window} stale={!issue.isCurrent} />
        </>
    );
}
