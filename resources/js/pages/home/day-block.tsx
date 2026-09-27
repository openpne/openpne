import { Link } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import { CommunityImage } from '@/components/community-image';
import { Heading } from '@/components/ui/heading';
import { stretchedLink } from '@/components/ui/surface';
import { messagesPhrase, morePhrase, responsesPhrase } from '@/lib/count-phrase';
import { useT } from '@/lib/i18n';
import { markedName } from '@/lib/identity-mark';
import { useDateFormat } from '@/lib/use-date-format';
import { StoryPicture } from './story';
import type { DayItem, DayName, DaySummary, DayTalk } from './types';

type Translate = ReturnType<typeof useT>;

const ROW = 'relative -mx-2 flex items-start gap-3 rounded-lg px-2 py-2 transition-colors hover:bg-muted/40 active:bg-muted/60';

const INLINE_LINK = 'inline-flex min-h-6 items-center hover:underline';

/** Where a day's block stands in the page, which is where the calendar sends its reader. */
export function dayAnchor(date: string): string {
    return `day-${date}`;
}

/** One wording for the block and for the calendar's link, so a stretch of days is heard as one in both. */
export function daysCovered(t: Translate, civilDate: (value: string, weekday?: boolean) => string, day: DaySummary): string {
    return day.days.from === day.days.to
        ? civilDate(day.date, true)
        : t(':from to :to', { from: civilDate(day.days.from), to: civilDate(day.days.to) });
}

/** A message with nothing the reader may have of it is called by its room. */
export function said(t: Translate, talk: DayTalk): string {
    if (talk.line === '') {
        return talk.group.name;
    }

    // The AI marker is text here as in a room's row: one truncated line has no place for a chip.
    return `${markedName(talk.speaker?.name ?? t('Withdrawn member'), talk.speaker?.isAi ?? false, t)}: ${talk.line}`;
}

function Meta({ item }: { item: DayItem }) {
    const t = useT();

    if (item.kind === 'story') {
        // Zero is left out rather than printed: the line says what happened, not what did not.
        return item.responses > 0 && <p className="text-xs text-muted-foreground">{responsesPhrase(t, item.responses)}</p>;
    }

    return (
        <p className="flex min-w-0 items-center gap-1.5 text-xs text-muted-foreground">
            <CommunityImage name={item.group.name} src={item.group.imageUrl} className="size-4" textClassName="text-3xs" decorative />
            {item.line !== '' && <span className="min-w-0 truncate">{item.group.name}</span>}
            <span className="shrink-0">{messagesPhrase(t, item.count)}</span>
        </p>
    );
}

/** Each item opens itself, not the day: the day is opened by its date. */
function Item({ item }: { item: DayItem }) {
    const t = useT();

    return (
        <li className={ROW}>
            <div className="min-w-0 flex-1 space-y-0.5">
                <p className="line-clamp-2 text-base break-words text-foreground">
                    <Link href={item.href} className={stretchedLink}>
                        {item.kind === 'story' ? item.headline : said(t, item)}
                    </Link>
                </p>
                <Meta item={item} />
            </div>
            {item.image && <StoryPicture image={item.image} shape="size-16 shrink-0 rounded-lg" sizes="4rem" />}
        </li>
    );
}

function Names({ label, names }: { label: string; names: DayName[] }) {
    const t = useT();

    if (names.length === 0) {
        return null;
    }

    return (
        // Set apart by space, not by a mark: a name may carry a mark of its own.
        <p className="flex flex-wrap items-center gap-x-3 text-sm text-muted-foreground">
            <span>{label}</span>
            {names.map((name) => (
                <Link key={name.id} href={name.href} className={`${INLINE_LINK} text-foreground`}>
                    {markedName(name.name, name.isAi ?? false, t)}
                </Link>
            ))}
        </p>
    );
}

export function DayBlock({ day }: { day: DaySummary }) {
    const t = useT();
    const { civilDate } = useDateFormat();

    return (
        <li id={dayAnchor(day.date)} className="scroll-mt-16 space-y-1 px-4 py-4 sm:px-5">
            <Heading as="h3" variant="minor">
                <Link href={day.href} className={`${INLINE_LINK} gap-1`}>
                    {daysCovered(t, civilDate, day)}
                    <ChevronRight className="size-4 shrink-0 text-muted-foreground" aria-hidden />
                </Link>
            </Heading>
            {day.items.length > 0 && (
                <ul>
                    {day.items.map((item) => (
                        <Item key={item.href} item={item} />
                    ))}
                </ul>
            )}
            <Names label={t('New members')} names={day.newcomers} />
            <Names label={t('New %communities%')} names={day.newGroups} />
            {day.more > 0 && (
                <p className="text-sm">
                    <Link href={day.href} className={`${INLINE_LINK} text-link`}>
                        {morePhrase(t, day.more)}
                    </Link>
                </p>
            )}
        </li>
    );
}
