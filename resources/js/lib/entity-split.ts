/**
 * Splits a plain-text body into text and entity segments over code-point ranges, mirroring the
 * Classic App\Support\EntityText (app/Support/EntityText.php). The shared cases are pinned by
 * tests/Unit/Support/EntityTextTest.php (PHP) and entity-split.test.ts (this file's sibling).
 *
 * Mentions and tags arrive as two lists and are merged here by offset, as <x-timeline-body> merges
 * them for EntityText. The two never intersect — a tag candidate overlapping a mention is dropped at
 * save time — so offset order is the whole merge.
 *
 * Text between entities is *not* linkified here: <EntityText> hands each text segment to
 * <UserText>, so the URL rules stay in linkify.ts alone. An entity's own text never goes through
 * them, which is what keeps a display name containing "www." from nesting an anchor.
 *
 * Escaping is the renderer's job — React escapes what this returns, so these are raw strings and
 * never HTML.
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
