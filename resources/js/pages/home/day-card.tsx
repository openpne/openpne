import { Link } from '@inertiajs/react';
import { Avatar } from '@/components/avatar';
import { CommunityImage } from '@/components/community-image';
import { Heading } from '@/components/ui/heading';
import { ListRow, stretchedLink } from '@/components/ui/surface';
import { newGroupsPhrase, newMembersPhrase, responsesPhrase, storiesPhrase, talkMessagesPhrase } from '@/lib/count-phrase';
import { useT } from '@/lib/i18n';
import { markedName } from '@/lib/identity-mark';
import { useDateFormat } from '@/lib/use-date-format';
import { StoryPicture } from './story';
import type { DayCounts, DaySummary, DayTop } from './types';

type Translate = ReturnType<typeof useT>;

const THUMB = 'size-24';

function headline(t: Translate, top: DayTop): string {
    switch (top.kind) {
        case 'story':
            return top.headline;
        case 'talk':
            return t('Talk in :group', { group: top.group.name });
        case 'newcomer': {
            const name = markedName(top.member.name, top.member.isAi, t);

            if (top.others === 0) {
                return t(':name joined', { name });
            }

            return top.others === 1
                ? t(':name and 1 other joined', { name })
                : t(':name and :count others joined', { name, count: top.others });
        }
        case 'newGroup':
            return t('New %community%: :name', { name: top.group.name });
    }
}

/** Zero is left out rather than printed: the line says what happened, not what did not. */
export function breakdown(t: Translate, counts: DayCounts): string[] {
    return [
        counts.stories > 0 && storiesPhrase(t, counts.stories),
        counts.responses > 0 && responsesPhrase(t, counts.responses),
        counts.talk > 0 && talkMessagesPhrase(t, counts.talk),
        counts.newcomers > 0 && newMembersPhrase(t, counts.newcomers),
        counts.newGroups > 0 && newGroupsPhrase(t, counts.newGroups),
    ].filter((phrase): phrase is string => phrase !== false);
}

/** Decorative throughout: the line beside it names what the picture is of. */
function Picture({ top }: { top: DayTop }) {
    switch (top.kind) {
        case 'story':
            return top.image && <StoryPicture image={top.image} shape={`${THUMB} shrink-0 rounded-lg`} sizes="6rem" />;
        case 'talk':
        case 'newGroup':
            return <CommunityImage name={top.group.name} src={top.group.imageUrl} className={THUMB} textClassName="text-3xl" decorative />;
        case 'newcomer':
            return (
                <Avatar
                    id={top.member.id}
                    name={top.member.name}
                    src={top.member.imageUrl}
                    color={top.member.avatarColor}
                    isAi={top.member.isAi}
                    size="lg"
                    decorative
                />
            );
    }
}

/** The date is the link, not the headline: a day is called by its date, whatever it leads with. */
export function DayCard({ day }: { day: DaySummary }) {
    const t = useT();
    const { civilDate } = useDateFormat();

    const date =
        day.days.from === day.days.to
            ? civilDate(day.date, true)
            : t(':from to :to', { from: civilDate(day.days.from), to: civilDate(day.days.to) });
    const counts = breakdown(t, day.counts);

    return (
        <ListRow rowLink chevron={day.top === null} className="items-start">
            <div className="min-w-0 flex-1 space-y-0.5">
                <Heading as="h3" variant="minor">
                    <Link href={day.href} className={stretchedLink}>
                        {date}
                    </Link>
                </Heading>
                {day.top && <p className="line-clamp-2 break-words text-base text-foreground">{headline(t, day.top)}</p>}
                {counts.length > 0 && (
                    // Set apart by space, not by a dot: a label may carry a dot of its own.
                    <ul className="flex flex-wrap gap-x-3 text-xs text-muted-foreground">
                        {counts.map((phrase) => (
                            <li key={phrase}>{phrase}</li>
                        ))}
                    </ul>
                )}
            </div>
            {day.top && <Picture top={day.top} />}
        </ListRow>
    );
}
