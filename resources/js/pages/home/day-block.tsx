import { Link, usePage } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import type { ReactNode } from 'react';
import { Avatar } from '@/components/avatar';
import { CommunityImage } from '@/components/community-image';
import { Heading } from '@/components/ui/heading';
import { stretchedLink } from '@/components/ui/surface';
import { messagesPhrase, morePhrase, responsesPhrase } from '@/lib/count-phrase';
import { useT } from '@/lib/i18n';
import { markedName } from '@/lib/identity-mark';
import { useDateFormat } from '@/lib/use-date-format';
import { useSiteDay } from '@/lib/use-site-day';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';
import { Dot, StoryPicture } from './story';
import type { DayStory, DaySummary, DayTalk } from './types';

type Translate = ReturnType<typeof useT>;

type DateFormat = ReturnType<typeof useDateFormat>;

/** Where a day's block stands in the page, which is where the calendar sends its reader. */
export function dayAnchor(date: string): string {
    return `day-${date}`;
}

/** One wording for the block, the calendar's link and the day's own band, so a day is heard as the same in all three. */
export function daysCovered(t: Translate, date: DateFormat, day: DaySummary): string {
    const { from, to } = day.bornDays;

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

function Story({ item }: { item: DayStory }) {
    const t = useT();

    return (
        <li className="relative">
            <p className="font-serif text-lg leading-snug break-words text-foreground">
                <Link href={item.href} className={stretchedLink}>
                    {item.headline}
                </Link>
            </p>
            {/* Zero is left out rather than printed: the line says what happened, not what did not. */}
            {item.responses > 0 && <p className="mt-0.5 text-xs text-muted-foreground">{responsesPhrase(t, item.responses)}</p>}
        </li>
    );
}

function Talk({ item }: { item: DayTalk }) {
    const t = useT();
    const speaker = item.speaker;
    const name = speaker?.name ?? t('%Withdrawn_member%');

    return (
        <li className="relative flex items-start gap-2.5">
            <Avatar
                id={speaker?.id ?? 0}
                name={name}
                src={speaker?.imageUrl ?? null}
                color={speaker?.avatarColor ?? null}
                isAi={speaker?.isAi ?? false}
                size="xs"
                decorative
            />
            <div className="min-w-0 flex-1">
                {/* Named in full: the line is cut where the row ends. */}
                <Link href={item.href} aria-label={said(t, item)} className={cn(stretchedLink, 'flex min-w-0 items-baseline gap-2')}>
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
            {item.image && <StoryPicture image={item.image} shape="size-12 shrink-0" sizes="3rem" />}
        </li>
    );
}

function Names({ label, children }: { label: string; children: ReactNode[] }) {
    if (children.length === 0) {
        return null;
    }

    return (
        <div className="flex items-start gap-2">
            <span className="w-20 shrink-0 text-2xs leading-6 text-muted-foreground">{label}</span>
            <ul className="flex min-w-0 flex-wrap gap-x-4 gap-y-1">{children}</ul>
        </div>
    );
}

function NameLink({ href, face, children }: { href: string; face: ReactNode; children: string }) {
    return (
        <li>
            <Link href={href} className="flex min-h-6 items-center gap-1.5 text-sm text-foreground hover:underline">
                {face}
                {children}
            </Link>
        </li>
    );
}

/** Each item opens itself; the day is opened by its date and by what it holds beyond these. */
export function DayBlock({ day }: { day: DaySummary }) {
    const t = useT();
    const date = useDateFormat();
    const { timezone } = usePage<PageProps>().props;
    // Read for the re-render alone: the date drops its year by the reader's year, which turns over at midnight.
    useSiteDay(timezone);
    const single = day.bornDays.from === day.bornDays.to;

    return (
        <li id={dayAnchor(day.date)} className={cn('scroll-mt-16 border-t border-foreground', day.more > 0 ? 'pb-1.5' : 'pb-5')}>
            <Heading as="h3" variant="group" face="serif">
                <Link href={day.href} className="flex min-h-12 items-center justify-between gap-2">
                    {single ? <time dateTime={day.bornDays.from}>{daysCovered(t, date, day)}</time> : daysCovered(t, date, day)}
                    <ChevronRight className="size-4.5 shrink-0" aria-hidden />
                </Link>
            </Heading>
            <div className="flex flex-col gap-3.5">
                {day.items.length > 0 && (
                    <ul className="flex flex-col gap-3.5">
                        {day.items.map((item) => (item.kind === 'story' ? <Story key={item.href} item={item} /> : <Talk key={item.href} item={item} />))}
                    </ul>
                )}
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
                {day.more > 0 && (
                    <Link href={day.href} className="-mt-1.5 flex min-h-11 items-center gap-0.5 self-start text-sm text-foreground hover:underline">
                        {morePhrase(t, day.more)}
                        <ChevronRight className="size-4" aria-hidden />
                    </Link>
                )}
            </div>
        </li>
    );
}
