import { Head, usePage } from '@inertiajs/react';
import { List, Panel } from '@/components/ui/surface';
import { useT } from '@/lib/i18n';
import { useDateFormat } from '@/lib/use-date-format';
import { cn } from '@/lib/utils';
import { GroupGrid } from '../unified/group-grid';
import { PeopleGrid } from '../unified/people-grid';
import { Colophon } from './colophon';
import { UpcomingEventRow } from './event-row';
import { FeatureArticle } from './feature-article';
import { IssueNav } from './issue-nav';
import { Masthead } from './masthead';
import { StoryCard } from './story-card';
import { StoryBriefs } from './story-list';
import { TalkBurstCard } from './talk-burst';
import type { Issue, IssuePageProps, IssueStory } from './types';
import { WelcomePanel } from './welcome';

const storyKey = (story: IssueStory): string => `${story.kind}-${story.item.id}`;

/**
 * How much of the issue there is decides how it is laid out, and the payload already says so: an
 * issue with one item has neither `features` nor `briefs`, one with two or three has `features`, and
 * one with four or more has `briefs`. So this is a switch over the shape rather than over a mode —
 * there is no state in which the wrong branch can be taken.
 *
 * One item is printed whole, because an issue with a single story is that story. Two or three stand
 * abreast as equals: ranking them past the first would be a claim the day's traffic does not support.
 * Past three, the lead keeps its card and the rest become rows — a fourth card is a column that
 * cannot be read across.
 */
function IssueStories({ issue }: { issue: Issue }) {
    // Every story it featured has since gone: the day's talk, faces and calendar are still an issue,
    // and this band is simply not one of the things it has.
    if (!issue.topStory) {
        return null;
    }

    if (issue.features) {
        const stories = [issue.topStory, ...issue.features];

        return (
            <div className={cn('grid gap-4', stories.length > 2 ? 'lg:grid-cols-3' : 'lg:grid-cols-2')}>
                {stories.map((story) => (
                    <StoryCard key={storyKey(story)} story={story} />
                ))}
            </div>
        );
    }

    if (issue.briefs) {
        return (
            <>
                <StoryCard story={issue.topStory} />
                <StoryBriefs briefs={issue.briefs} />
            </>
        );
    }

    return <FeatureArticle story={issue.topStory} />;
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
 * Every optional section is drawn exactly when its key is present. Nothing counts to zero and says
 * so — an empty section is a section the issue does not have.
 */
export default function HomeIssue() {
    const t = useT();
    const { issue, prev, next, publishTime, auth, enabledFeatures } = usePage<IssuePageProps>().props;
    const date = useDateFormat();

    if (issue === null) {
        return (
            <>
                <Head title={t('Home')} />
                {/* Today's dateline with no number: there is no issue to number yet, and the site
                    still has a front page — it just has nothing on it. */}
                <Masthead date={date.siteDay(new Date().toISOString()) ?? ''} />
                {auth.user && <WelcomePanel name={auth.user.name} enabledFeatures={enabledFeatures} />}
                <p className="text-sm text-muted-foreground">{t('The first post will run in the next issue.')}</p>
                <Colophon publishTime={publishTime} stale={false} />
            </>
        );
    }

    return (
        <>
            <Head title={t('No. :number', { number: issue.number })} />
            <Masthead date={issue.date} number={issue.number} />

            <IssueStories issue={issue} />

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
            <Colophon publishTime={publishTime} stale={!issue.isCurrent} />
        </>
    );
}
