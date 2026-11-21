import { Link, usePage } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import type { ReactNode } from 'react';
import { Avatar } from '@/components/avatar';
import { CommunityImage } from '@/components/community-image';
import { KindMark } from '@/components/kind-mark';
import { Heading } from '@/components/ui/heading';
import { stretchedLink } from '@/components/ui/surface';
import { messagesPhrase, morePhrase, responsesPhrase } from '@/lib/count-phrase';
import { useT } from '@/lib/i18n';
import { markedName } from '@/lib/identity-mark';
import { useDateFormat } from '@/lib/use-date-format';
import { useSiteDay } from '@/lib/use-site-day';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';
import { StoryPicture } from './story';
import type { DayStory, DaySummary, DayTalk } from './types';

type Translate = ReturnType<typeof useT>;

type DateFormat = ReturnType<typeof useDateFormat>;

const ROW = 'relative -mx-2 flex items-start gap-3 rounded-lg px-2 py-2 transition-colors hover:bg-muted/40 active:bg-muted/60';

const INLINE_LINK = 'inline-flex min-h-6 items-center hover:underline';

/** Where a day's block stands in the page, which is where the calendar sends its reader. */
export function dayAnchor(date: string): string {
    return `day-${date}`;
}

export function daysCovered(t: Translate, date: DateFormat, { from, to }: { from: string; to: string }): string {
    return from === to ? date.civilMonthDay(from, true) : t(':from to :to', date.civilDaySpan(from, to));
}

/** A message with nothing the reader may have of it is called by its room. */
export function said(t: Translate, talk: DayTalk): string {
    if (talk.line === '') {
        return talk.group.name;
    }

    // The AI marker is text here as in a room's row: one truncated line has no place for a chip.
    return `${markedName(talk.speaker?.name ?? t('%Withdrawn_member%'), talk.speaker?.isAi ?? false, t)}: ${talk.line}`;
}

function Dot() {
    return <span aria-hidden>·</span>;
}

function Story({ item }: { item: DayStory }) {
    const t = useT();

    return (
        <li className={ROW}>
            <div className="min-w-0 flex-1 space-y-0.5">
                <KindMark kind={item.kind} group={item.groupName ?? undefined} />
                <p className="line-clamp-2 text-base break-words text-foreground">
                    <Link href={item.href} className={stretchedLink}>
                        {item.headline}
                    </Link>
                </p>
                {/* Zero is left out rather than printed: the line says what happened, not what did not. */}
                {item.responses > 0 && <p className="text-xs text-muted-foreground">{responsesPhrase(t, item.responses)}</p>}
            </div>
        </li>
    );
}

function Talk({ item }: { item: DayTalk }) {
    const t = useT();
    const speaker = item.speaker;
    const name = speaker?.name ?? t('%Withdrawn_member%');

    return (
        <li className={cn(ROW, 'flex-col items-stretch gap-0.5')}>
            {/* Over the face rather than beside it: every item opens on its mark at the same edge. */}
            <KindMark kind="talk" />
            <div className="flex items-start gap-3">
                <Avatar
                    id={speaker?.id ?? 0}
                    name={name}
                    src={speaker?.imageUrl ?? null}
                    color={speaker?.avatarColor ?? null}
                    isAi={speaker?.isAi ?? false}
                    size="xs"
                    decorative
                />
                <div className="min-w-0 flex-1 space-y-0.5">
                    {/* Named in full: the line is cut where the row ends. */}
                    <Link href={item.href} aria-label={said(t, item)} className={cn(stretchedLink, 'flex min-w-0 items-baseline gap-2 text-foreground')}>
                        {item.line !== '' && <span className="shrink-0 text-xs">{markedName(name, speaker?.isAi ?? false, t)}</span>}
                        <span className="min-w-0 truncate text-sm">{item.line === '' ? item.group.name : item.line}</span>
                    </Link>
                    <p className="flex min-w-0 items-center gap-1.5 text-xs text-muted-foreground">
                        {item.line !== '' && (
                            <>
                                <span className="min-w-0 truncate">{item.group.name}</span>
                                <Dot />
                            </>
                        )}
                        <span className="shrink-0">{messagesPhrase(t, item.count)}</span>
                    </p>
                </div>
                {item.image && <StoryPicture image={item.image} shape="size-12 shrink-0 rounded-lg" sizes="3rem" />}
            </div>
        </li>
    );
}

function Names({ label, children }: { label: string; children: ReactNode[] }) {
    if (children.length === 0) {
        return null;
    }

    return (
        // Set apart by space, not by a mark: a name may carry a mark of its own.
        <p className="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-muted-foreground">
            <span>{label}</span>
            {children}
        </p>
    );
}

function NameLink({ href, face, children }: { href: string; face: ReactNode; children: string }) {
    return (
        <Link href={href} className={cn(INLINE_LINK, 'gap-1.5 text-foreground')}>
            {face}
            {children}
        </Link>
    );
}

/** Each item opens itself; the day is opened by its date and by what it holds beyond these. */
export function DayBlock({ day }: { day: DaySummary }) {
    const t = useT();
    const date = useDateFormat();
    const { timezone } = usePage<PageProps>().props;
    // Read for the re-render alone: the date drops its year by the site's year, which turns over at the site's midnight.
    useSiteDay(timezone);
    const single = day.bornDays.from === day.bornDays.to;

    return (
        <li id={dayAnchor(day.date)} className="scroll-mt-below-place-3 space-y-1 px-4 py-4 sm:px-5">
            <Heading as="h3" variant="minor">
                <Link href={day.href} className={cn(INLINE_LINK, 'gap-1')}>
                    {single ? <time dateTime={day.bornDays.from}>{daysCovered(t, date, day.bornDays)}</time> : daysCovered(t, date, day.bornDays)}
                    <ChevronRight className="size-4 shrink-0 text-muted-foreground" aria-hidden />
                </Link>
            </Heading>
            {day.items.length > 0 && (
                <ul>
                    {day.items.map((item) => (item.kind === 'talk' ? <Talk key={item.href} item={item} /> : <Story key={item.href} item={item} />))}
                </ul>
            )}
            <div className="space-y-1">
                <Names label={t('New members')}>
                    {day.newcomers.map((person) => (
                        <NameLink
                            key={person.id}
                            href={person.href}
                            face={<Avatar id={person.id} name={person.name} src={person.imageUrl} color={person.avatarColor} isAi={person.isAi} size="xs" decorative />}
                        >
                            {markedName(person.name, person.isAi, t)}
                        </NameLink>
                    ))}
                </Names>
                <Names label={t('New %communities%')}>
                    {day.newGroups.map((group) => (
                        <NameLink
                            key={group.id}
                            href={group.href}
                            face={<CommunityImage name={group.name} src={group.imageUrl} className="size-6" textClassName="text-3xs" decorative />}
                        >
                            {group.name}
                        </NameLink>
                    ))}
                </Names>
            </div>
            {day.more > 0 && (
                <p className="text-sm">
                    <Link href={day.href} className={cn(INLINE_LINK, 'text-link')}>
                        {morePhrase(t, day.more)}
                    </Link>
                </p>
            )}
        </li>
    );
}
