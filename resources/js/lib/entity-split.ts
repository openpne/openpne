/**
 * Mirrors App\Support\EntityText, with the shared cases pinned on both sides
 * (docs/internals/body-text.md, "Render authority is the server"). Mentions and tags never intersect,
 * so offset order is the whole merge, and an entity's own text is never linkified, which keeps a
 * display name containing `www.` from nesting an anchor.
 */

/** Half-open [offset, offset + length) in Unicode code points, ascending and non-overlapping. */
interface EntityRange {
    offset: number;
    length: number;
}

export interface MentionEntity extends EntityRange {
    memberId: number;
}

export interface TagEntity extends EntityRange {
    /** Normalized (NFKC + lowercase): what the tag page is addressed by, not what the range shows. */
    tag: string;
}

export type EntitySegment =
    | { kind: 'text'; text: string }
    | { kind: 'mention'; text: string; memberId: number }
    | { kind: 'hashtag'; text: string; tag: string };

export function splitEntities(
    text: string | null | undefined,
    mentions: MentionEntity[],
    tags: TagEntity[] = [],
): EntitySegment[] {
    // Array.from splits on code points, the unit the offsets are counted in — string indexing would
    // cut an astral emoji in half and shift every later range.
    const points = Array.from(String(text ?? ''));

    const entities = [
        ...mentions.map((mention) => ({ ...mention, kind: 'mention' as const })),
        ...tags.map((tag) => ({ ...tag, kind: 'hashtag' as const })),
    ].sort((a, b) => a.offset - b.offset);

    const segments: EntitySegment[] = [];
    let cursor = 0;

    for (const entity of entities) {
        const text = points.slice(entity.offset, entity.offset + entity.length).join('');
        segments.push({ kind: 'text', text: points.slice(cursor, entity.offset).join('') });
        segments.push(
            entity.kind === 'mention'
                ? { kind: 'mention', text, memberId: entity.memberId }
                : { kind: 'hashtag', text, tag: entity.tag },
        );
        cursor = entity.offset + entity.length;
    }
    segments.push({ kind: 'text', text: points.slice(cursor).join('') });

    return segments;
}
