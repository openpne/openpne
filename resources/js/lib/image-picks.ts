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

/** A typeless pick counts as a picture: the browser may not know a HEIC, and the decoder settles it. */
export function isPicture(file: File): boolean {
    return file.type === '' || file.type.startsWith('image/');
}

/**
 * A file that is not a picture goes up as picked, so the server's attachment cap is the only one
 * it meets; refusing it here spares the member a 413 that loses the draft. A picture is shrunk first
 * and the server's image rule answers it.
 */
export function withinAttachmentCap(picked: File[], maxBytes: number): { files: File[]; refused: boolean } {
    const files = picked.filter((file) => isPicture(file) || file.size <= maxBytes);

    return { files, refused: files.length < picked.length };
}

/** The cap as the member reads it, in whole megabytes. */
export function megabytes(bytes: number): string {
    return `${Math.floor(bytes / (1024 * 1024))} MB`;
}
