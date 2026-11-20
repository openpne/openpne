import { Head, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { Colophon } from './colophon';
import { UpcomingEventRow } from './event-row';
import { IssueNav } from './issue-nav';
import { DoubleRule, Masthead } from './masthead';
import { GroupNames, PeopleNames } from './name-grid';
import { SectionLabel } from './section-label';
import { SiteNotice } from './site-notice';
import { LeadStory, SideStory, StoryPair, StoryRow } from './story';
import { TalkBurstSection } from './talk-burst';
import type { Issue, IssuePageProps, IssueStory } from './types';
import { WelcomePanel } from './welcome';

const storyKey = (story: IssueStory): string => `${story.kind}-${story.id}`;

/** How many stories stand beside the lead before the rest become rows, not a duration. */
const SECONDS = 2;

/**
 * Rank alone decides the placement (docs/internals/home-issues.md, "Rendering"). A lone second takes
 * the full width instead of half of it.
 */
function Stories({ stories }: { stories: IssueStory[] }) {
    const [lead, ...rest] = stories;
    const seconds = rest.slice(0, SECONDS);
    const [left, right] = seconds;
    const rows = rest.slice(SECONDS);

    // A type guard, not an empty state: the band is absent from the payload when it has nothing in
    // it, so `stories` is never `[]` and there is always a lead to open with.
    if (lead === undefined) {
        return null;
    }

    return (
        <div className="mt-6 flex flex-col gap-6">
            <LeadStory story={lead} />
            {left && right ? (
                <StoryPair stories={[left, right]} />
            ) : (
                seconds.map((story) => <SideStory key={storyKey(story)} story={story} />)
            )}
            {rows.length > 0 && (
                <ul>
                    {rows.map((story) => (
                        <StoryRow key={storyKey(story)} story={story} />
                    ))}
                </ul>
            )}
        </div>
    );
}

/** The first section under the stories stands further off than the ones after it. */
function Section({ label, first, children }: { label: string; first: boolean; children: ReactNode }) {
    return (
        <div className={first ? 'mt-10' : 'mt-7'}>
            <SectionLabel>{label}</SectionLabel>
            {children}
        </div>
    );
}

function Sections({ issue }: { issue: Issue }) {
    const t = useT();
    const sections: { key: string; label: string; body: ReactNode }[] = [];

    if (issue.talkBursts) {
        sections.push({
            key: 'talk',
            label: t('From %community% talk'),
            body: (
                <div className="mt-5 flex flex-col gap-3.5">
                    {issue.talkBursts.map((burst, index) => (
                        <TalkBurstSection key={burst.group.id} burst={burst} first={index === 0} />
                    ))}
                </div>
            ),
        });
    }

    if (issue.upcomingEvents) {
        sections.push({
            key: 'events',
            label: t('Upcoming events'),
            body: (
                <ul className="mt-2.5">
                    {issue.upcomingEvents.map((event) => (
                        <UpcomingEventRow key={event.id} event={event} />
                    ))}
                </ul>
            ),
        });
    }

    if (issue.newcomers) {
        sections.push({ key: 'newcomers', label: t('New members'), body: <div className="mt-2.5"><PeopleNames people={issue.newcomers} /></div> });
    }

    if (issue.newGroups) {
        sections.push({ key: 'groups', label: t('New %communities%'), body: <div className="mt-2.5"><GroupNames groups={issue.newGroups} /></div> });
    }

    return sections.map((section, index) => (
        <Section key={section.key} label={section.label} first={index === 0}>
            {section.body}
        </Section>
    ));
}

/**
 * One issue (号), the same for every member, serving `/` and a dated URL through `home/archive`.
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
                <article className="flex flex-col">
                    <Masthead days={null} prev={null} next={null} />
                    {auth.user && (
                        <div className="mt-6">
                            <WelcomePanel name={auth.user.name} enabledFeatures={enabledFeatures} />
                        </div>
                    )}
                    <p className={cn('text-sm text-muted-foreground', auth.user ? 'mt-4' : 'mt-6')}>
                        {t('The first post will appear here the next morning.')}
                    </p>
                </article>
            </>
        );
    }

    return (
        <>
            <Head title={t('What happened')} />
            <SiteNotice notice={notice} />
            <article className="flex flex-col">
                <Masthead days={issue.bornDays} prev={prev} next={next} />
                {issue.stories && <Stories stories={issue.stories} />}
                <Sections issue={issue} />
                <div className="mt-6">
                    <DoubleRule heavyFirst={false} />
                </div>
                <IssueNav prev={prev} next={next} monthHref={issue.monthHref} />
                <Colophon window={issue.window} />
            </article>
        </>
    );
}
