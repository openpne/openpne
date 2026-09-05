/** Server contract: PostImages::MAX_IMAGES, the cap every post-with-attachments form enforces. */
export const MAX_POST_IMAGES = 3;

/**
 * Capping is decided before anything is decoded, so a fifty-photo selection is not canvas-processed
 * and then thrown away. What the cap turned away is reported rather than dropped silently.
 */
export function acceptPicks<T>(held: T[], picked: T[], max: number = MAX_POST_IMAGES): { files: T[]; refused: boolean } {
    const room = Math.max(0, max - held.length);

    return { files: [...held, ...picked.slice(0, room)], refused: picked.length > room };
}
