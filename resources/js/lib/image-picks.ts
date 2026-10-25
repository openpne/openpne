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

/** The types in an accept list, exactly; a file's type is matched whole, never as a substring. */
export function acceptedTypes(accept: string): Set<string> {
    return new Set(
        accept
            .split(',')
            .map((entry) => entry.trim())
            .filter((entry) => entry.startsWith('image/')),
    );
}

/**
 * A picture is what the server's processor reads (the shipped accept list); a typeless pick or an
 * image type outside it goes up as a file, which the server also decides by content.
 */
export function isPicture(file: File, accept: string): boolean {
    return acceptedTypes(accept).has(file.type);
}

/**
 * A file that is not a picture goes up as picked, so the server's attachment cap is the only one
 * it meets; refusing it here spares the member a 413 that loses the draft. A picture is shrunk first
 * and the server's image rule answers it.
 */
export function withinAttachmentCap(picked: File[], upload: { accept: string; attachmentMaxBytes: number }): { files: File[]; refused: boolean } {
    const files = picked.filter((file) => isPicture(file, upload.accept) || file.size <= upload.attachmentMaxBytes);

    return { files, refused: files.length < picked.length };
}

/** The cap as the member reads it: whole megabytes, a fraction kept when there is one, kilobytes under one. */
export function sizeLabel(bytes: number): string {
    if (bytes < 1024 * 1024) {
        return `${Math.round(bytes / 1024)} KB`;
    }
    const megabytes = bytes / (1024 * 1024);

    return `${Number.isInteger(megabytes) ? megabytes : megabytes.toFixed(1)} MB`;
}
