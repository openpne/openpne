export interface ProfileStats {
    diaries: number;
    activity: number;
    friends: number;
    groups: number;
}

/**
 * The trailing "No profile to show." panel renders only when the page would otherwise end after the
 * identity header: no profile details AND every digest stat at zero. Judged on the stats, not the
 * preview arrays — activity has no preview section, so an activity-only member would read as empty
 * while the header's stats row says otherwise.
 */
export function profileIsBlank(bio: string | null, age: number | null, fieldCount: number, stats: ProfileStats | null): boolean {
    const digestEmpty = stats === null || Object.values(stats).every((count) => count === 0);

    return !bio && age === null && fieldCount === 0 && digestEmpty;
}
