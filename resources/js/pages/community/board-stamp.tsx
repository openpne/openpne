import { Timestamp } from '@/components/timestamp';
import { useT } from '@/lib/i18n';

interface BoardStampProps {
    commentCount: number;
    bumpedAt: string;
}

/** The board's stamp under the label its count decides: bumped_at is the last comment's instant, or the post's when there is none. */
export function BoardStamp({ commentCount, bumpedAt }: BoardStampProps) {
    const t = useT();

    return (
        <>
            {commentCount > 0 ? t('Last comment') : t('Posted')}: <Timestamp at={bumpedAt} preset="listStamp" />
        </>
    );
}

/** The stamp after an event row's open date, from the `sm` breakpoint up: a phone-wide row has room for the date alone. */
export function EventBoardStamp({ commentCount, bumpedAt }: BoardStampProps) {
    return (
        <span className="hidden sm:inline">
            {' '}
            <span aria-hidden>·</span> <BoardStamp commentCount={commentCount} bumpedAt={bumpedAt} />
        </span>
    );
}
