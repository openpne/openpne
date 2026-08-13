/** Server contract: PostImages::MAX_IMAGES, the cap every post-with-attachments form enforces. */
export const MAX_POST_IMAGES = 3;

/**
 * Fold a file picker's selection into the one already held, under the cap.
 *
 * Capping is decided here, before anything is decoded: a fifty-photo selection must not be
 * canvas-processed fifty times to then be thrown away. What the cap turned away is reported rather
 * than dropped silently — a selection that quietly shrinks reads as the picker losing files.
 */
export function acceptPicks<T>(held: T[], picked: T[], max: number = MAX_POST_IMAGES): { files: T[]; refused: boolean } {
    const room = Math.max(0, max - held.length);

    return { files: [...held, ...picked.slice(0, room)], refused: picked.length > room };
}
