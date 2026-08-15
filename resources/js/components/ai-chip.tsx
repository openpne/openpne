import { useT } from '@/lib/i18n';

/**
 * Marks the member a name belongs to as an AI account. Site policy is that an AI account is
 * recognisable as one wherever it speaks, so this rides beside the name on every surface a member is
 * named on.
 *
 * The muted register the community role badges use, not a warning colour: it states what the account
 * is, the way "Admin" does — nothing here is a thing to be careful of.
 *
 * Renders nothing for a human, so a call site passes the fact rather than guarding on it.
 */
export function AiChip({ isAi }: { isAi: boolean }) {
    const t = useT();

    if (!isAi) {
        return null;
    }

    return <span className="shrink-0 rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground">{t('AI')}</span>;
}
