import type { MentionEntity, TagEntity } from '@/lib/entity-split';

/** A body cut at its first line break, with each entity range following the half it falls in. */
export interface LeadSplit {
    /** The first line, without its trailing newline. The whole body when there is no break. */
    lead: string;
    /** Everything after the first newline; empty when there is no break. */
    rest: string;
    leadMentions: MentionEntity[];
    leadTags: TagEntity[];
    restMentions: MentionEntity[];
    restTags: TagEntity[];
}

/**
 * Splits a body into its first line and the remainder, so a post with no title can be headlined by
 * the line its author opened with and still have the rest drawn as a body.
 *
 * Offsets are **code points**, the unit the entity ranges are counted in (see lib/entity-split.ts),
 * so the split is taken over `Array.from` rather than by string index — a UTF-16 cut would halve an
 * astral character and shift every range after it.
 *
 * A range is kept only where it lies whole: one inside the lead stays in the lead, one inside the
 * rest is rebased by the lead's length plus the newline, and one that **straddles the break is
 * dropped from both halves**. Nothing splits an entity: half a mention is not a link to anyone, and
 * the alternative — keeping it in one half at a truncated length — would draw a link over text that
 * is no longer the name it matched.
 */
export function splitLead(body: string, mentions: MentionEntity[] = [], tags: TagEntity[] = []): LeadSplit {
    const points = Array.from(body ?? '');
    const breakAt = points.indexOf('\n');

    if (breakAt === -1) {
        return { lead: points.join(''), rest: '', leadMentions: mentions, leadTags: tags, restMentions: [], restTags: [] };
    }

    const restFrom = breakAt + 1;
    const inLead = (range: { offset: number; length: number }): boolean => range.offset + range.length <= breakAt;
    const inRest = (range: { offset: number; length: number }): boolean => range.offset >= restFrom;
    const rebase = <T extends { offset: number }>(range: T): T => ({ ...range, offset: range.offset - restFrom });

    return {
        lead: points.slice(0, breakAt).join(''),
        rest: points.slice(restFrom).join(''),
        leadMentions: mentions.filter(inLead),
        leadTags: tags.filter(inLead),
        restMentions: mentions.filter(inRest).map(rebase),
        restTags: tags.filter(inRest).map(rebase),
    };
}
