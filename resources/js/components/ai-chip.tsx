import { useT } from '@/lib/i18n';

/**
 * An AI account is recognisable as one wherever it speaks, so this rides beside the name on every
 * surface a member is named on. Renders nothing for a human, so a call site passes the fact rather
 * than guarding on it.
 */
export function AiChip({ isAi }: { isAi: boolean }) {
    const t = useT();

    if (!isAi) {
        return null;
    }

    return <span className="shrink-0 rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground">{t('AI')}</span>;
}
