/**
 * Splits a plain-text body into text and entity segments over code-point ranges, mirroring the
 * Classic App\Support\EntityText (app/Support/EntityText.php). The shared cases are pinned by
 * tests/Unit/Support/EntityTextTest.php (PHP) and entity-split.test.ts (this file's sibling).
 *
 * Text between entities is *not* linkified here: <EntityText> hands each text segment to
 * <UserText>, so the URL rules stay in linkify.ts alone. An entity's own text never goes through
 * them, which is what keeps a display name containing "www." from nesting an anchor.
 *
 * Escaping is the renderer's job — React escapes what this returns, so these are raw strings and
 * never HTML.
 */
export interface MentionEntity {
    memberId: number;
    /** Half-open [offset, offset + length) in Unicode code points, ascending and non-overlapping. */
    offset: number;
    length: number;
}

export type EntitySegment = { kind: 'text'; text: string } | { kind: 'mention'; text: string; memberId: number };

export function splitEntities(text: string | null | undefined, entities: MentionEntity[]): EntitySegment[] {
    // Array.from splits on code points, the unit the offsets are counted in — string indexing would
    // cut an astral emoji in half and shift every later range.
    const points = Array.from(String(text ?? ''));

    const segments: EntitySegment[] = [];
    let cursor = 0;

    for (const entity of entities) {
        segments.push({ kind: 'text', text: points.slice(cursor, entity.offset).join('') });
        segments.push({ kind: 'mention', text: points.slice(entity.offset, entity.offset + entity.length).join(''), memberId: entity.memberId });
        cursor = entity.offset + entity.length;
    }
    segments.push({ kind: 'text', text: points.slice(cursor).join('') });

    return segments;
}
